<?php

/**
 * Executable program Controller Extension
 *
 * Copyright 2016-2022 秋水之冰 <27206617@qq.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace Nervsys\Ext;

use Nervsys\Core\Factory;
use Nervsys\Core\Mgr\ProcMgr;

class libExeC extends Factory
{
    public ProcMgr $procMgr;

    public string $proc_id;

    private array $event_fn = [
        'onMonitor' => null, //callback(string $proc_id): void. Process monitor
        'onCommand' => null, //callback(string $proc_id): array $commands. Read commands and return to libExeC
        'onOutput'  => null, //callback(string $proc_id, string $proc_output): void. Catch output message from async process
        'onExit'    => null  //callback(string $proc_id): void. Exit event monitor
    ];

    /**
     * @param string $proc_id
     */
    public function __construct(string $proc_id)
    {
        $this->proc_id = &$proc_id;
        unset($proc_id);
    }

    /**
     * @param callable $callable
     *
     * @return $this
     */
    public function onMonitor(callable $callable): self
    {
        $this->event_fn[__FUNCTION__] = &$callable;

        unset($callable);
        return $this;
    }

    /**
     * @param callable $callable
     *
     * @return $this
     */
    public function onCommand(callable $callable): self
    {
        $this->event_fn[__FUNCTION__] = &$callable;

        unset($callable);
        return $this;
    }

    /**
     * @param callable $callable
     *
     * @return $this
     */
    public function onOutput(callable $callable): self
    {
        $this->event_fn[__FUNCTION__] = &$callable;

        unset($callable);
        return $this;
    }

    /**
     * @param callable $callable
     *
     * @return $this
     */
    public function onExit(callable $callable): self
    {
        $this->event_fn[__FUNCTION__] = &$callable;

        unset($callable);
        return $this;
    }

    /**
     * Set process locale env
     *
     * @param string $locale
     *
     * @return $this
     */
    public function setLocale(string $locale): self
    {
        setlocale(LC_ALL, $locale);
        putenv('LC_ALL=' . $locale);

        unset($locale);
        return $this;
    }

    /**
     * @param string $command
     * @param string $working_path
     * @param int    $watch_timeout
     *
     * @return void
     * @throws \Exception
     * @throws \Throwable
     */
    public function create(string $command, string $working_path = '', int $watch_timeout = 20000): void
    {
        $this->procMgr = ProcMgr::new($command, $working_path)
            ->setWatchTimeout($watch_timeout)
            ->createProc(0);

        unset($command, $working_path, $watch_timeout);

        while ($this->procMgr->isProcAlive(0)) {
            if (is_callable($this->event_fn['onMonitor'])) {
                call_user_func($this->event_fn['onMonitor'], $this->proc_id);
            }

            if (is_callable($this->event_fn['onOutput'])) {
                $this->procMgr->fiberMgr->async(
                    $this->procMgr->fiberMgr->await([$this->procMgr, 'await'], [0]),
                    function (string $output): void
                    {
                        call_user_func($this->event_fn['onOutput'], $this->proc_id, $output);
                        unset($output);
                    }
                );
            }

            if (is_callable($this->event_fn['onCommand'])) {
                $this->procMgr->fiberMgr->async(
                    $this->procMgr->fiberMgr->await($this->event_fn['onCommand'], [$this->proc_id]),
                    function (array $commands): void
                    {
                        foreach ($commands as $command) {
                            $this->procMgr->writeProc(0, $command);
                        }

                        unset($commands, $command);
                    }
                );
            }

            $this->procMgr->fiberMgr->commit();
        }

        if (is_callable($this->event_fn['onExit'])) {
            call_user_func($this->event_fn['onExit'], $this->proc_id);
        }

        $this->procMgr->closeProc(0);
    }
}