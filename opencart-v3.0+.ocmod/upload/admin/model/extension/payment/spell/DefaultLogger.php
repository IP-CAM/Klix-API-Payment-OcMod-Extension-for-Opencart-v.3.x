<?php
class DefaultLogger
{
    private $log;

    /** @param Log $log */
    public function __construct($log)
    {
        $this->log = $log;
    }

    /** @param string $msg */
    public function log($msg)
    {
        $this->log->write($msg);
    }
}
