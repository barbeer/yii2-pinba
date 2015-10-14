<?php
/**
 * Class Pinba
 *
 * @author Dmitri Cherepovski <codernumber1@gmail.com>
 * @package yiiPinba\component
 */

namespace yiiPinba\component;

use yii\base\Component;
use yii\base\InvalidConfigException;
use yiiPinba\behavior\TimersRemindBehavior;

/**
 * Yii2 pinba wrapper
 *
 * @author Dmitri Cherepovski <codernumber1@gmail.com>
 * @package yiiPinba\component
 */
class Pinba extends Component
{
    const DEFAULT_MAX_TAG_LENGTH = 64;
    const TRUNCATION_PREFIX = '...';

    const CLIENT_NONE = 0;
    const CLIENT_PHP_EXTENSION = 1;
    const CLIENT_PHP_CODE = 2;

    /** @var int Maximum length for tag strings */
    public $maxTagLength = self::DEFAULT_MAX_TAG_LENGTH;

    /**
     * @var bool TRUE if warnings should be logged for timers which were not
     * explicitly stopped
     */
    public $remindAboutTimers = true;

    /**
     * @var string|null Server from the PHP config gets used by default
     * @see https://github.com/tony2001/pinba_engine/wiki/PHP-extension#pinbaserver
     */
    public $server;

    /** @var resource[] */
    private $runningTimers = [];

    /** @var int */
    private $clientUsed;

    /**
     * Returns the type of pinba client used
     *
     * @return int
     */
    private function getClientUsed()
    {
        if (extension_loaded('pinba')) {
            $this->clientUsed = self::CLIENT_PHP_EXTENSION;
        } elseif (function_exists('pinba_timer_start')) {
            $this->clientUsed = self::CLIENT_PHP_CODE;
        } else {
            $this->clientUsed = self::CLIENT_NONE;
        }
        return $this->clientUsed;
    }

    /**
     * @inheritdoc
     */
    public function init()
    {
        if (($clientUsed = $this->getClientUsed()) === self::CLIENT_NONE) {
            throw new InvalidConfigException('Pinba functionale not available');
        }

        parent::init();

        if ($this->remindAboutTimers) {
            \Yii::$app->attachBehavior('pinbaTimersRemind', [
                'class' => TimersRemindBehavior::className(),
                'pinba' => $this,
            ]);
        }

        if ($clientUsed === self::CLIENT_PHP_EXTENSION) {
            ini_set('pinba.enabled', true);
            if ($this->server) {
                ini_set('pinba.server', $this->server);
            }
        }
    }

    /**
     * Returns the tags ready to be sent to Pinba
     *
     * @param string $tags
     *
     * @return array
     */
    private function formatTags($tags)
    {
        return array_map(function ($tag) {
            if (($len = strlen($tag)) <= $this->maxTagLength) {
                return $tag;
            }
            $truncationToken = '...' . $len;
            return substr_replace(
                $tag,
                $truncationToken,
                $this->maxTagLength - strlen($truncationToken)
            );
        }, $tags);
    }

    /**
     * Starts the timer
     *
     * @param string $token
     * @param array $tags
     *
     * @return bool Operation success
     */
    public function startTimer($token, $tags = [])
    {
        if (isset($this->runningTimers[$token])) {
            return false;
        }
        $actualTags = array_merge($tags, ['timerToken' => $token]);
        $this->runningTimers[$token] = pinba_timer_start($this->formatTags($actualTags));
        return true;
    }

    /**
     * Stops the timer
     *
     * @param string $token
     *
     * @return bool Operation success
     */
    public function stopTimer($token)
    {
        if (! isset($this->runningTimers[$token])) {
            return false;
        }
        $timer = $this->runningTimers[$token];
        unset($this->runningTimers[$token]);
        if (! pinba_timer_get_info($timer)['started']) {
            return false;
        }
        return pinba_timer_stop($timer);
    }

    /**
     * Checks if there are running any timers registered
     *
     * @return bool
     */
    public function hasRunningTimers()
    {
        return !empty($this->runningTimers);
    }

    /**
     * Returns the tokens of the timers registered
     *
     * @return string[]
     */
    public function getRunningTimerTokens()
    {
        return array_keys($this->runningTimers);
    }


    /**
     * Stops and flushes all timers
     */
    public function flush()
    {
        pinba_timers_stop();
        pinba_flush();
    }

    /**
     * Creates stopped Pinba timer so it can be later flushed to Pinba
     *
     * @param array $tags
     * @param float $value
     */
    public function profile($tags, $value)
    {
        pinba_timer_add($this->formatTags($tags), $value);
    }
}