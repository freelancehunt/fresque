<?php
/**
 * Fresque Class File
 *
 * PHP 5
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @link       https://github.com/kamisama/Fresque
 * @since      0.1.0
 * @package    Fresque
 * @subpackage Fresque.lib
 * @author     Wan Qi Chen <kami@kamisama.me>
 * @copyright  Copyright 2012, Wan Qi Chen <kami@kamisama.me>
 *
 * @license    MIT License (http://www.opensource.org/licenses/mit-license.php)
 */

namespace Freelancehunt\Fresque;

use ezcConsoleException;
use ezcConsoleInput;
use ezcConsoleMenuDialog;
use ezcConsoleOption;
use ezcConsoleOutput;
use ezcConsoleMenuDialogOptions;
use DateTime;
use Freelancehunt\Redisent\Redisent;
use Freelancehunt\Resque\Resque;
use Freelancehunt\Resque\ResqueRedis;
use Freelancehunt\Resque\Stat;
use Freelancehunt\Resque\Worker;
use Redis;
use RedisException;

define('DS', DIRECTORY_SEPARATOR);

class Fresque
{
    public ezcConsoleInput  $input;
    public ezcConsoleOutput $output;

    public array $settings = [
        'Default' => [
            'verbose' => false,
        ],
    ];
    public array $runtime = [];
    public array $commandTree;
    public bool  $debug   = false;

    public static $Resque = Resque::class;
    public static $Worker = Worker::class;

    public ResqueStatus|null $ResqueStatus = null;
    public ResqueStats|null  $ResqueStats  = null;

    private const CHECK_STARTED_WORKER_BUFFER_TIME = 100000;

    private const VERSION = '2.0.0';


    public function __construct()
    {
        $command = array_splice($_SERVER['argv'], 1, 1);
        $command = empty($command) ? null : $command[0];

        $this->input  = new ezcConsoleInput();
        $this->output = new ezcConsoleOutput();

        $this->input->registerOption(
            new ezcConsoleOption(
                'u',
                'user',
                ezcConsoleInput::TYPE_STRING,
                null,
                false,
                'User running the workers',
                'User running the workers'
            )
        );

        $this->input->registerOption(
            new ezcConsoleOption(
                'q',
                'queue',
                ezcConsoleInput::TYPE_STRING,
                null,
                false,
                'Name of the queue. If multiple queues, separate with comma.',
                'Name of the queue. If multiple queues, separate with comma.'
            )
        );

        $this->input->registerOption(
            new ezcConsoleOption(
                'i',
                'interval',
                ezcConsoleInput::TYPE_INT,
                null,
                false,
                'Pause time in seconds between each worker round',
                'Pause time in seconds between each worker round'
            )
        );

        $this->input->registerOption(
            new ezcConsoleOption(
                'n',
                'workers',
                ezcConsoleInput::TYPE_INT,
                null,
                false,
                'Number of workers to create',
                'Number of workers to create'
            )
        );

        $this->input->registerOption(
            new ezcConsoleOption(
                'f',
                'force',
                ezcConsoleInput::TYPE_NONE,
                null,
                false,
                'Force workers stopping, forcing all the current jobs to finish (and fail)',
                'Force workers stopping, forcing all the current jobs to finish (and fail)'
            )
        );

        $this->input->registerOption(
            new ezcConsoleOption(
                'v',
                'verbose',
                ezcConsoleInput::TYPE_NONE,
                null,
                false,
                'Log more verbose informations',
                'Log more verbose informations'
            )
        );

        $this->input->registerOption(
            new ezcConsoleOption(
                'g',
                'debug',
                ezcConsoleInput::TYPE_NONE,
                null,
                false,
                'Print debug informations',
                'Print debug informations'
            )
        );

        $this->input->registerOption(
            new ezcConsoleOption(
                's',
                'host',
                ezcConsoleInput::TYPE_STRING,
                null,
                false,
                'Redis server hostname',
                'Redis server hostname (eg. localhost, 127.0.0.1, etc ...)'
            )
        );

        $this->input->registerOption(
            new ezcConsoleOption(
                'p',
                'port',
                ezcConsoleInput::TYPE_INT,
                null,
                false,
                'Redis server port',
                'Redis server port'
            )
        );

        $this->input->registerOption(
            new ezcConsoleOption(
                'l',
                'log',
                ezcConsoleInput::TYPE_STRING,
                null,
                false,
                'Log file path',
                'Absolute path to the log file'
            )
        );

        $this->input->registerOption(
            new ezcConsoleOption(
                'b',
                'lib',
                ezcConsoleInput::TYPE_STRING,
                null,
                false,
                'PHPresque library path',
                'Absolute path to your PHPResque library'
            )
        );

        $this->input->registerOption(
            new ezcConsoleOption(
                'a',
                'autoloader',
                ezcConsoleInput::TYPE_STRING,
                null,
                false,
                'Application autoloader path',
                'Absolute path to your application autoloader file'
            )
        );

        $this->input->registerOption(
            new ezcConsoleOption(
                'c',
                'config',
                ezcConsoleInput::TYPE_STRING,
                null,
                false,
                'Configuration file path',
                'Absolute path to your configuration file'
            )
        );

        $this->input->registerOption(
            new ezcConsoleOption(
                'd',
                'loghandler',
                ezcConsoleInput::TYPE_STRING,
                null,
                false,
                'Log Handler',
                'Handler used for logging'
            )
        );

        $this->input->registerOption(
            new ezcConsoleOption(
                'r',
                'handlertarget',
                ezcConsoleInput::TYPE_STRING,
                null,
                false,
                'Log Handler options',
                'Arguments used for initializing the handler'
            )
        );

        $this->input->registerOption(
            new ezcConsoleOption(
                'w',
                'all',
                ezcConsoleInput::TYPE_NONE,
                null,
                false,
                'Stop all workers',
                'Stop all workers'
            )
        );

        $this->input->registerOption(new ezcConsoleOption('h', 'help'));

        $this->output->formats->title->color = 'yellow';
        $this->output->formats->title->style = 'bold';

        $this->output->formats->subtitle->color = 'blue';
        $this->output->formats->subtitle->style = 'bold';

        $this->output->formats->warning->color = 'red';

        $this->output->formats->bold->style = 'bold';

        $this->output->formats->highlight->color = 'blue';

        $this->output->formats->success->color = 'green';
        $this->output->formats->success->style = 'normal';

        try {
            $this->input->process();
        } catch (ezcConsoleException $e) {
            $this->output->outputLine($e->getMessage() . "\n", 'failure');
            die();
        }

        $this->commandTree = [
            'start'          => [
                'help'    => 'Start a new worker',
                'options' => ['u' => 'username', 'q' => 'queue name',
                              'i' => 'num', 'n' => 'num', 'l' => 'path', 'v', 'g']],
            'stop'           => [
                'help'    => 'Stop workers',
                'options' => ['f', 'w', 'g']],
            'pause'          => [
                'help'    => 'Pause workers',
                'options' => ['w', 'g']],
            'resume'         => [
                'help'    => 'Resume paused workers',
                'options' => ['w', 'g']],
            'restart'        => [
                'help'    => 'Restart all workers',
                'options' => []],
            'load'           => [
                'help'    => 'Load workers defined in your configuration file',
                'options' => ['l']],
            'tail'           => [
                'help'    => 'Monitor the log file',
                'options' => []],
            'enqueue'        => [
                'help'    => 'Enqueue a new job',
                'options' => []],
            'stats'          => [
                'help'    => 'Display resque statistics',
                'options' => []],
            'test'           => [
                'help'    => 'Test your fresque configuration file',
                'options' => ['u' => 'username', 'q' => 'queue name',
                              'i' => 'num', 'n' => 'num', 'l' => 'path']],
            'reset'          => [
                'help'    => 'Clear all workers saved statuses',
                'options' => []],
            'help'           => [
                'help'    => 'Print help',
                'options' => []],
        ];

        $this->callCommand($command);
    }

    /**
     *
     * @return  void
     * @since  1.2.0
     */
    public function callCommand($command)
    {
        if (($settings = $this->loadSettings($command)) === false) {
            exit(1);
        }

        $args = $this->input->getArguments();

        $globalOptions = ['s' => 'host', 'p' => 'port', 'b' => 'path',
                          'c' => 'path', 'a' => 'path', 'd' => 'handler', 'r' => 'args,',
        ];

        if ($command === null || !array_key_exists($command, $this->commandTree)) {
            $this->help($command);
        } else {
            if ($this->input->getOption('help')->value === true) {
                $this->output->outputLine();
                $this->output->outputLine($this->commandTree[$command]['help']);

                if (!empty($this->commandTree[$command]['options'])) {
                    $this->output->outputLine("\nAvailable options\n", 'subtitle');

                    foreach ($this->commandTree[$command]['options'] as $name => $arg) {
                        $opt = $this->input->getOption(is_numeric($name) ? $arg : $name);
                        $o   = (!empty($opt->short)
                                ? '-' . $opt->short : '  ') . ' ' . (is_numeric($name) ? ''
                                : '<' . $arg . '>');

                        $this->output->outputLine(sprintf('%-15s --%-15s %s', $o, $opt->long, $opt->longhelp));
                    }
                }

                $this->output->outputLine("\nGlobal options\n", 'subtitle');

                foreach ($globalOptions as $name => $arg) {
                    $opt = $this->input->getOption(is_numeric($name) ? $arg : $name);
                    $o   = '-' . $opt->short . ' ' . (is_numeric($name) ? '' : '<' . $arg . '>');

                    $this->output->outputLine(sprintf('%-15s --%-15s %s', $o, $opt->long, $opt->longhelp));
                }

                $this->output->outputLine();

            } else {
                $allowed = array_merge($this->commandTree[$command]['options'], $globalOptions);
                foreach ($allowed as $name => &$arg) {
                    if (!is_numeric($name)) {
                        $arg = $name;
                    }
                }

                $unrecognized = array_diff(array_keys($this->input->getOptionValues()), array_values($allowed));
                if (!empty($unrecognized)) {
                    $this->output->outputLine(
                        'Invalid options ' . implode(
                            ', ',
                            array_map(
                                function ($opt) {
                                    return '-' . $opt;
                                },
                                $unrecognized
                            )
                        ) . ' will be ignored',
                        'warning'
                    );
                }
                $this->setResqueBackend();

                $this->ResqueStatus = $this->initResqueStatus();
                $this->ResqueStats  = $this->initResqueStats();
                $this->{$command}();
            }
        }
    }

    /**
     * Start workers
     *
     * @return  void
     */
    public function start($args = null)
    {
        if ($args === null) {
            $this->outputTitle('Creating workers');
        } else {
            $this->runtime = $args;
        }

        $pidFile = (isset($this->runtime['Fresque']['tmpdir']) ?
                $this->runtime['Fresque']['tmpdir'] : dirname(__DIR__) . DS . '..' . DS . 'tmp')
            . DS . str_replace('.', '', microtime(true));
        $count   = $this->runtime['Default']['workers'];

        $this->debug('Will start ' . $count . ' workers');

        for ($i = 1; $i <= $count; $i++) {

            $libraryPath = $this->runtime['Fresque']['lib'];
            $logFile     = $this->runtime['Log']['filename'];
            $resqueBin   = $this->getResqueBinFile($this->runtime['Fresque']['lib']);

            $libraryPath = rtrim($libraryPath, '/');

            // build environment variables string to be passed to the worker
            $env_vars = "";
            if (!empty($this->runtime['Env'])) {
                foreach ($this->runtime['Env'] as $env_name => $env_value) {
                    // if only the name is supplied, we get the value from environment
                    if (strlen($env_value) == 0) {
                        $env_value = getenv($env_name);
                    }
                    $env_vars .= $env_name . '=' . escapeshellarg($env_value) . " \\\n";
                }
            }

            $cmd = 'nohup ' . ($this->runtime['Default']['user'] !== $this->getProcessOwner() ? ('sudo -u ' . escapeshellarg($this->runtime['Default']['user'])) : "") . " \\\n" .
                'bash -c "cd ' .
                escapeshellarg($libraryPath) . '; ' . " \\\n" .
                $env_vars .
                (($this->runtime['Default']['verbose']) ? 'VVERBOSE' : 'VERBOSE') . '=true ' . " \\\n" .
                'QUEUE=' . escapeshellarg($this->runtime['Default']['queue']) . " \\\n" .
                'PIDFILE=' . escapeshellarg($pidFile) . " \\\n" .
                'APP_INCLUDE=' . escapeshellarg($this->runtime['Fresque']['include']) . " \\\n" .
                'RESQUE_PHP=' . escapeshellarg($this->runtime['Fresque']['lib'] . DS . 'src' . DS . 'Resque' . DS . 'Resque.php') . " \\\n" .
                'INTERVAL=' . escapeshellarg($this->runtime['Default']['interval']) . " \\\n" .
                'REDIS_BACKEND=' . escapeshellarg($this->runtime['Redis']['host'] . ':' . $this->runtime['Redis']['port']) . " \\\n" .
                'REDIS_DATABASE=' . escapeshellarg($this->runtime['Redis']['database']) . " \\\n" .
                'REDIS_NAMESPACE=' . escapeshellarg($this->runtime['Redis']['namespace']) . " \\\n" .
                (!empty($this->runtime['Redis']['password']) ? 'REDIS_PASSWORD=' . escapeshellarg($this->runtime['Redis']['password']) . " \\\n" : '') .
                'COUNT=' . 1 . " \\\n" .
                'LOGHANDLER=' . escapeshellarg($this->runtime['Log']['handler']) . " \\\n" .
                'LOGHANDLERTARGET=' . escapeshellarg($this->runtime['Log']['target']) . " \\\n" .
                'php ' . escapeshellarg($resqueBin) . " \\\n";
            $cmd .= ' >> ' . escapeshellarg($logFile) . ' 2>&1" >/dev/null 2>&1 &';

            $this->debug('Starting worker (' . $i . ')');
            $this->debug("Running command :\n\t" . str_replace("\n", "\n\t", $cmd));

            $this->exec($cmd);

            $this->output->outputText('Starting worker ');

            $success = false;
            $attempt = 7;
            while ($attempt-- > 0) {
                for ($x = 0; $x < 3; $x++) {
                    $this->output->outputText(".", 0);
                    usleep(self::CHECK_STARTED_WORKER_BUFFER_TIME);
                }

                if (false !== $pid = $this->checkStartedWorker($pidFile)) {

                    $success = true;
                    $this->output->outputLine(' Done', 'success');

                    $this->debug('Registering worker #' . $pid . ' to list of active workers');

                    $workerSettings            = $this->runtime;
                    $workerSettings['workers'] = 1;

                    $this->ResqueStatus->addWorker($pid, $workerSettings);

                    break;
                }
            }

            if (!$success) {
                $this->output->outputLine(' Fail', 'failure');
            }
        }

        if ($args === null) {
            $this->output->outputLine();
        }
    }

    /**
     * Stop workers
     *
     * @return  void
     */
    public function stop()
    {
        $ResqueStatus = $this->ResqueStatus;

        $this->debug('Searching for active workers');
        $options                               = new SendSignalCommandOptions();
        $options->title                        = 'Stopping workers';
        $options->noWorkersMessage             = 'There is no workers to stop';
        $options->allOption                    = 'Stop all workers';
        $options->selectMessage                = 'Worker to stop';
        $options->actionMessage                = 'stopping';
        $options->workers                      = $this->getActiveWorkers();
        $options->signal                       = $this->input->getOption('force')->value === true ? 'TERM' : 'QUIT';
        $options->successCallback              = function ($pid, $workerName) use ($ResqueStatus) {
            $ResqueStatus->removeWorker($pid);
        };

        $this->sendSignal($options);
    }

    /**
     * Pause workers
     *
     * @return  void
     */
    public function pause()
    {
        $ResqueStatus = $this->ResqueStatus;

        $activeWorkers = $this->getActiveWorkers();
        array_walk(
            $activeWorkers,
            function (&$worker) {
                return $worker = (string) $worker;
            }
        );
        $pausedWorkers = call_user_func([$this->ResqueStatus, 'getPausedWorkers']);

        $this->debug('Searching for active workers');
        $options                               = new SendSignalCommandOptions();
        $options->title                        = 'Pausing workers';
        $options->noWorkersMessage             = 'There is no workers to pause';
        $options->allOption                    = 'Pause all workers';
        $options->selectMessage                = 'Worker to pause';
        $options->actionMessage                = 'pausing';
        $options->workers                      = array_diff($activeWorkers, $pausedWorkers);
        $options->signal                       = 'USR2';
        $options->successCallback              = function ($pid, $workerName) use ($ResqueStatus) {
            $ResqueStatus->setPausedWorker($workerName);
        };

        $this->sendSignal($options);
    }

    /**
     * Resume workers
     *
     * @return  void
     */
    public function resume()
    {
        $ResqueStatus = $this->ResqueStatus;

        $this->debug('Searching for paused workers');
        $options                               = new SendSignalCommandOptions();
        $options->title                        = 'Resuming workers';
        $options->noWorkersMessage             = 'There is no paused workers to resume';
        $options->allOption                    = 'Resume all workers';
        $options->selectMessage                = 'Worker to resume';
        $options->actionMessage                = 'resuming';
        $options->workers                      = call_user_func([$this->ResqueStatus, 'getPausedWorkers']);
        $options->signal                       = 'CONT';
        $options->successCallback              = function ($pid, $workerName) use ($ResqueStatus) {
            $ResqueStatus->setPausedWorker($workerName, false);
        };

        $this->sendSignal($options);
    }

    /**
     * Send a Signal to a worker system process
     *
     * @return void
     * @since  1.2.0
     */
    public function sendSignal($options)
    {
        $this->outputTitle($options->title);

        $force = $this->input->getOption('force')->value;
        $all   = $this->input->getOption('all')->value;

        if ($force) {
            $this->debug("'FORCE' option detected");
        }

        if ($all) {
            $this->debug("'ALL' option detected");
        }

        if (!isset($options->formatListItem)) {
            $resqueStats   = $this->ResqueStats;
            $ResqueStatus  = $this->ResqueStatus;
            $fresque       = $this;
            $listFormatter = function ($worker) use ($resqueStats, $ResqueStatus, $fresque) {
                return sprintf(
                    '%s, started %s ago',
                    $worker,
                    $fresque->formatDateDiff($resqueStats->getWorkerStartDate($worker)),
                );
            };
        } else {
            $listFormatter = $options->formatListItem;
        }

        if (empty($options->workers)) {
            $this->output->outputLine($options->noWorkersMessage, 'failure');
        } else {
            sort($options->workers);
            $this->debug('Found ' . $options->getWorkersCount() . ' workers');

            $workerIndex = [];
            if (!$all && $options->getWorkersCount() > 1) {
                $i         = 1;
                $menuItems = [];
                foreach ($options->workers as $worker) {
                    $menuItems[$i++] = $listFormatter($worker);
                }

                $menuItems['all'] = $options->allOption;

                $index = $this->getUserChoice(
                    $options->listTitle,
                    $options->selectMessage . ':',
                    $menuItems
                );

                if ($index === 'all') {
                    $workerIndex = range(1, $options->getWorkersCount());
                } else {
                    $workerIndex[] = $index;
                }

            } else {
                $workerIndex = range(1, $options->getWorkersCount());
            }

            foreach ($workerIndex as $index) {
                $worker = $options->workers[$index - 1];

                [$hostname, $pid, $queue] = explode(':', (string) $worker);

                $this->debug('Sending -' . $options->signal . ' signal to process ID ' . $pid);

                $this->output->outputText($options->actionMessage . ' ' . $pid . ' ... ');

                $killResponse = $this->kill($options->signal, $pid);
                $options->onSuccess($pid, (string) $worker);

                if ($killResponse['code'] === 0) {
                    $this->output->outputLine('Done', 'success');
                } else {
                    $this->output->outputLine($killResponse['message'], 'failure');
                }
            }
        }

        $this->output->outputLine();
    }

    /**
     * Load workers from configuration
     *
     * @return  void
     */
    public function load()
    {
        $this->outputTitle('Loading predefined workers');
        $debug = $this->debug;

        if (!isset($this->runtime['Queues']) || empty($this->runtime['Queues'])) {
            $this->output->outputLine("You have no configured workers to load.\n", 'failure');
        } else {
            $this->output->outputLine(sprintf('Loading %s workers', count($this->runtime['Queues'])));

            $config = $this->config;

            foreach ($this->runtime['Queues'] as $queue) {
                $queue['config'] = $config;
                $queue['debug']  = $debug;
                $this->loadSettings('load', $queue);
                $this->start($this->runtime);
            }
        }

        $this->output->outputLine();
    }

    /**
     * Restart all workers
     *
     * @return  void
     */
    public function restart()
    {
        $workers = $this->ResqueStatus->getWorkers();

        $this->outputTitle('Restarting workers');

        if (!empty($workers)) {
            $this->stop();

            foreach ($workers as $worker) {
                $this->start($worker);
            }
        } else {
            $this->output->outputLine('No workers to restart', 'failure');
        }

        $this->output->outputLine();
    }

    /**
     * Tail a log file
     *
     * If more than one log file exists, will display a menu dialog with a list
     * of log files to choose from.
     *
     * @return  void
     */
    public function tail()
    {
        $logs    = [];
        $i       = 1;
        $workers = $this->ResqueStatus->getWorkers();

        foreach ($workers as $worker) {
            if ($worker['Log']['filename'] != '') {
                $logs[] = $worker['Log']['filename'];
            }
            if ($worker['Log']['handler'] == 'RotatingFile') {
                $fileInfo = pathinfo($worker['Log']['target']);
                $pattern  = $fileInfo['dirname'] . DS . $fileInfo['filename'] . '-*' .
                    (!empty($fileInfo['extension']) ? '.' . $fileInfo['extension'] : '');

                $logs = array_merge($logs, glob($pattern));
            }
        }

        $logs = array_values(array_unique($logs));

        $this->outputTitle('Tailing log file');
        if (empty($logs)) {
            $this->output->outputLine('No log file to tail', 'failure');

            return;
        } elseif (count($logs) == 1) {
            $index = 1;
        } else {
            $menuOptions = new ezcConsoleMenuDialogOptions(
                [
                    'text'       => 'Log files list',
                    'selectText' => 'Log to tail :',
                    'validator'  => new DialogMenuValidator(array_combine(range(1, count($logs)), $logs)),
                ]
            );
            $menuDialog  = new ezcConsoleMenuDialog($this->output, $menuOptions);
            do {
                $menuDialog->display();
            } while ($menuDialog->hasValidResult() === false);

            $index = $menuDialog->getResult();
        }

        $this->output->outputLine('Tailing ' . $logs[$index - 1], 'subtitle');
        $this->tailCommand($logs[$index - 1]);
    }

    /**
     * Add a job to a queue
     *
     * @return  void
     */
    public function enqueue()
    {
        $this->outputTitle('Queuing a job');

        $args = $this->input->getArguments();

        if (!empty($args) && count($args) >= 2) {
            $queue = array_shift($args);
            $class = array_shift($args);

            $result = $this->enqueueJob($queue, $class, $args);
            $this->output->outputLine('The job was enqueued successfully', 'success');
            $this->output->outputLine('Job ID : #' . $result . "\n");
        } else {
            $this->output->outputLine('Enqueue takes at least 2 arguments', 'failure');
            $this->output->outputLine('Usage : enqueue <queue> <job> <args>');
            $this->output->outputLine('   queue <string>  Name of the queue');
            $this->output->outputLine('   job   <string>  Job class name');
            $this->output->outputLine('   args  <string>  Comma separated list of arguments');
            $this->output->outputLine();
        }
    }

    /**
     * Print some stats about the workers
     *
     * @return  void
     */
    public function stats()
    {
        $workers = call_user_func([$this->ResqueStats, 'getWorkers']);
        // List of all queues
        $queues = array_unique(call_user_func([$this->ResqueStats, 'getQueues']));

        // List of queues monitored by a worker
        $activeQueues = [];
        foreach ($workers as $worker) {
            $tokens       = explode(':', $worker);
            $activeQueues = array_merge($activeQueues, explode(',', array_pop($tokens)));
        }

        $this->outputTitle('Resque statistics');

        $this->output->outputLine();
        $this->output->outputLine('Jobs Stats', 'subtitle');
        $this->output->outputLine('   ' . sprintf('Processed Jobs : %10s', number_format($this->getResqueStat('processed'))));
        $this->output->outputLine('   ' . sprintf('Failed Jobs    : %10s', number_format($this->getResqueStat('failed'))), 'failure');
        $this->output->outputLine();

        $count = [];
        $this->output->outputLine('Queues Stats', 'subtitle');
        for ($i = count($queues) - 1; $i >= 0; --$i) {
            $count[$queues[$i]] = call_user_func_array([$this->ResqueStats, 'getQueueLength'], [$queues[$i]]);
            if (!in_array($queues[$i], $activeQueues) && $count[$queues[$i]] == 0) {
                unset($queues[$i]);
            }
        }

        $this->output->outputLine('   ' . sprintf('Queues count : %d', count($queues)));
        foreach ($queues as $queue) {
            $this->output->outputText(sprintf("\t- %-20s : %10s pending jobs", $queue, number_format($count[$queue])));
            if (!in_array($queue, $activeQueues)) {
                $this->output->outputText(' (unmonitored queue)', 'failure');
            }
            $this->output->outputText("\n");
        }
        $this->output->outputLine();

        $this->output->outputLine('Workers Stats', 'subtitle');
        $this->output->outputLine('  Active Workers : ' . count($workers));

        if (!empty($workers)) {

            $pausedWorkers = call_user_func([$this->ResqueStatus, 'getPausedWorkers']);

            foreach ($workers as $worker) {
                $this->output->outputText('    Worker : ' . $worker, 'bold');
                if (in_array((string) $worker, $pausedWorkers)) {
                    $this->output->outputText(' (Paused)', 'success');
                }
                $this->output->outputText("\n");

                $startDate = $this->ResqueStats->getWorkerStartDate($worker);

                $this->output->outputLine(
                    '     - Started on     : ' . $startDate
                );
                $this->output->outputLine(
                    '     - Uptime         : ' .
                    $this->formatDateDiff(new DateTime($startDate))
                );
                $this->output->outputLine('     - Processed Jobs : ' . $worker->getStat('processed'));
                $worker->getStat('failed') == 0
                    ? $this->output->outputLine('     - Failed Jobs    : ' . $worker->getStat('failed'))
                    : $this->output->outputLine('     - Failed Jobs    : ' . $worker->getStat('failed'), 'failure');
            }
        }

        $this->output->outputLine("\n");
    }

    /**
     * Reset worker statuses
     *
     * @return void
     * @since  1.2.0
     */
    public function reset()
    {
        $this->debug('Emptying the worker database');
        $this->ResqueStatus->clearWorkers();
        $this->output->outputLine('Fresque state has been reseted', 'success');
    }

    /**
     * Test and validate the configuration file
     *
     * @return  void
     */
    public function test()
    {
        $this->outputTitle('Testing configuration');

        $results = $this->testConfig(true);
        foreach ($results as $name => $r) {
            $this->output->outputText($name . ' ' . str_repeat('.', 24 - strlen($name)) . ' ');
            if ($r === true) {
                $this->output->outputText("OK\n", 'success');
            } else {
                $this->output->outputText($r . "\n", 'failure');
            }
        }

        if (array_filter(array_values($results), function ($val) {
                return $val !== true;
            }) === []) {
            $this->output->outputLine("\nYour settings seems ok", 'success');
        } else {
            $this->output->outputLine("\nError detected in your settings", 'failure');
        }

        $this->output->outputLine("\nYour configuration", 'subtitle');

        foreach ($this->runtime as $cat => $confs) {
            $this->output->outputLine('[' . $cat . ']', 'bold');
            foreach ($this->runtime[$cat] as $name => $conf) {
                if (!is_array($conf)) {
                    $this->output->outputText('   ' . $name . str_repeat(' ', 10 - strlen($name)));
                    $this->output->outputLine($conf);
                } else {
                    $this->output->outputLine('   ' . $name, 'highlight');
                    foreach ($conf as $q => $o) {
                        $this->output->outputText('      ' . $q . str_repeat(' ', 10 - strlen($q)));
                        $this->output->outputLine($o);
                    }
                }
            }
        }
    }

    public function testConfig($test = false)
    {
        $results = [
            'Redis configuration'    => true,
            'Redis server'           => true,
            'Log File'               => true,
            'PHPResque library'      => true,
            'Application autoloader' => true,
        ];

        if (!isset($this->runtime['Redis']['host']) || !isset($this->runtime['Redis']['port'])) {
            $results['Redis configuration'] = 'Unable to read redis server configuration';
        }

        $this->runtime['Fresque']['lib'] = $this->absolutePath($this->runtime['Fresque']['lib']);

        if (!is_dir($this->runtime['Fresque']['lib'])) {
            $results['PHPResque library']
                = 'Unable to found PHP Resque library. Check that the path is valid, and directory is readable';
        }

        try {
            if (class_exists(ResqueRedis::class)) {
                $redis = @new ResqueRedis($this->runtime['Redis']['host'], (int) $this->runtime['Redis']['port']);
            } elseif (class_exists(Redis::class)) {
                $redis = new Redis();
                @$redis->connect($this->runtime['Redis']['host'], (int) $this->runtime['Redis']['port']);
            } elseif (class_exists(Redisent::class)) {
                $redis = @new Redisent($this->runtime['Redis']['host'], (int) $this->runtime['Redis']['port']);
            } else {
                $results['Redis server'] = 'Unable to find Redis Api';
            }
        } catch (RedisException $e) {
            $results['Redis server'] = 'Unable to connect to Redis server at '
                . $this->runtime['Redis']['host'] . ':' . $this->runtime['Redis']['port'];
        }

        $this->runtime['Log']['filename'] = $this->absolutePath($this->runtime['Log']['filename']);

        $logPath = pathinfo($this->runtime['Log']['filename'], PATHINFO_DIRNAME);
        if (!is_dir($logPath)) {
            $results['Log File'] = 'The directory for the log file does not exists';
        } elseif (!is_writable($logPath)) {
            $results['Log File'] = 'The directory for the log file is not writable';
        }

        $output = [];
        exec('id ' . $this->runtime['Default']['user'] . ' 2>&1', $output, $status);
        if ($status != 0) {
            $results['user'] = sprintf('User %s does not exists', $this->runtime['Default']['user']);
        }

        $this->runtime['Fresque']['include'] = $this->absolutePath($this->runtime['Fresque']['include']);
        if (!file_exists($this->runtime['Fresque']['include'])) {
            $results['Application autoloader'] = 'Your application autoloader file was not found';
        }

        return $results;
    }

    /**
     * Convert options from various source to formatted options
     * understandable by Fresque
     *
     * @return bool true if settings contains no errors
     */
    public function loadSettings($command, $args = null)
    {
        $options = ($args === null) ? $this->input->getOptionValues(true) : $args;

        $this->config = isset($options['config']) ? $options['config'] : '.' . DS . 'fresque.ini';
        if (!file_exists($this->config)) {
            $this->output->outputLine("The config file '$this->config' was not found", 'failure');

            return false;
        }

        $this->debug = isset($options['debug']) ? $options['debug'] : false;

        $this->runtime = parse_ini_file($this->config, true);

        if (!isset($this->runtime['type'])) {
            $this->runtime['type'] = 'regular';
        }

        $settings = [
            'Redis'     => [
                'host',
                'port',
                'database',
                'namespace',
            ],
            'Fresque'   => [
                'lib',
                'include',
            ],
            'Default'   => [
                'queue',
                'interval',
                'workers',
                'user',
                'verbose',
            ],
            'Log'       => [
                'filename',
                'handler',
                'target',
            ],
            'Env'       => [],
        ];

        foreach ($settings as $scope => $param_names) {
            foreach ($param_names as $option) {
                if (isset($options[$option])) {
                    $this->runtime[$scope][$option] = $options[$option];
                }
            }
        }

        if (isset($this->runtime['Queues']) && !empty($this->runtime['Queues'])) {
            foreach ($this->runtime['Queues'] as $name => $options) {
                if (!isset($this->runtime['Queues'][$name]['queue'])) {
                    $this->runtime['Queues'][$name]['queue'] = $name;
                }
            }
        }

        $this->runtime['Default']['verbose'] = ($this->input->getOption('verbose')->value)
            ? $this->input->getOption('verbose')->value : $this->settings['Default']['verbose'];

        // Shutdown application if there is error in the config
        if ($command !== 'test') {
            $results = $this->testConfig();
            if (!empty($results)) {
                $fail = false;

                foreach ($results as $name => $mess) {
                    if ($mess !== true) {
                        $fail = true;
                        $this->output->outputLine($mess, 'failure');
                    }
                }

                if ($fail) {
                    $this->output->outputLine();

                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Print help/welcome message
     *
     * @return void
     * @since  1.2.0
     */
    public function help($command = null)
    {
        $this->outputTitle('Welcome to Fresque');
        $this->output->outputLine('Fresque ' . self::VERSION . ' by Wan Chen (Kamisama) (2013)');

        if (!array_key_exists($command, $this->commandTree)
            && $command !== null
            && ($command !== '--help' && $command !== '-h')
        ) {
            $this->output->outputLine("\nUnrecognized command : " . $command, 'failure');
        }

        $this->output->outputLine();
        $this->output->outputLine("Available commands\n", 'subtitle');

        foreach ($this->commandTree as $name => $opt) {
            $this->output->outputText($name . str_repeat(' ', 15 - strlen($name)), 'bold');
            $this->output->outputText($opt['help'] . "\n");
        }

        $this->output->outputLine("\nUse <command> --help to get more infos about a command\n");
    }

    /**
     * Print a pretty title
     *
     * @param string $title   The title to print
     * @param bool   $primary True to print a big title, else print a small title
     *
     * @return  void
     * @since 1.0.0
     */
    public function outputTitle($title, $primary = true)
    {
        $l = strlen($title);
        if ($primary) {
            $this->output->outputLine(str_repeat('-', $l), 'title');
        }
        $this->output->outputLine($title, $primary ? 'title' : 'subtitle');
        if ($primary) {
            $this->output->outputLine(str_repeat('-', $l), 'title');
        }
    }

    /**
     * A sweet interval formatting, will use the two biggest interval parts.
     * On small intervals, you get minutes and seconds.
     * On big intervals, you get months and days.
     * Only the two biggest parts are used.
     *
     * @param DateTime      $start
     * @param DateTime|null $end
     *
     * @codeCoverageIgnore
     * @return string
     * @link http://www.php.net/manual/en/dateinterval.format.php
     */
    public function formatDateDiff($start, $end = null)
    {
        if ($start === null) {
            $start = new DateTime();
        }

        if (!($start instanceof DateTime)) {
            $start = new DateTime($start);
        }

        if ($end === null) {
            $end = new DateTime();
        }

        if (!($end instanceof DateTime)) {
            $end = new DateTime($start);
        }

        $interval = $end->diff($start);
        $doPlural = function (
            $nb,
            $str
        ) {
            return $nb > 1 ? $str . 's' : $str;
        };

        $format_parts = [];
        if ($interval->y !== 0) {
            $format_parts[] = '%y ' . $doPlural($interval->y, 'year');
        }
        if ($interval->m !== 0) {
            $format_parts[] = '%m ' . $doPlural($interval->m, 'month');
        }
        if ($interval->d !== 0) {
            $format_parts[] = '%d ' . $doPlural($interval->d, 'day');
        }
        if ($interval->h !== 0) {
            $format_parts[] = '%h ' . $doPlural($interval->h, 'hour');
        }
        if ($interval->i !== 0) {
            $format_parts[] = '%i ' . $doPlural($interval->i, 'minute');
        }
        if ($interval->s !== 0) {
            if (!count($format_parts)) {
                return 'less than a minute';
            } else {
                $format_parts[] = '%s ' . $doPlural($interval->s, 'second');
            }
        }

        // TODO: Can be sometimes, maybe just in tests, need to check it.
        if (empty($format_parts)) {
            return '-';
        }

        // We use the two biggest parts
        if (count($format_parts) > 1) {
            $format = array_shift($format_parts) . ' and ' . array_shift($format_parts);
        } else {
            $format = array_pop($format_parts);
        }

        // Prepend 'since ' or whatever you like
        return $interval->format($format);
    }

    /**
     * Return the absolute path to a file
     *
     * @param string $path Path to convert
     *
     * @return string Absolute path to the file
     */
    private function absolutePath($path)
    {
        if (substr($path, 0, 2) === './') {
            $path = dirname(__DIR__) . DS . substr($path, 2);
        } elseif (substr($path, 0, 1) !== '/' || substr($path, 0, 3) === '../') {
            $path = dirname(__DIR__) . DS . $path;
        }

        return rtrim($path, DS);
    }

    /**
     * Print debugging information
     *
     * @param string $string Information to print
     *
     * @return void
     * @since  1.2.0
     */
    public function debug($string)
    {
        if ($this->debug) {
            $this->output->outputLine('[DEBUG] ' . $string, 'success');
        }
    }

    /**
     * Return the php-resque executable file
     *
     * Maintain backward compatibility, as newer version of
     * php-resque has that file in another location
     *
     * @param String $base Php-resque folder path
     *
     * @return String Relative path to php-resque executable file
     * @since  1.1.6
     */
    protected function getResqueBinFile($base)
    {
        $paths = [
            'bin' . DS . 'resque',
            'bin' . DS . 'resque.php',
            'resque.php',
        ];

        foreach ($paths as $path) {
            if (file_exists($base . DS . $path)) {
                return '.' . DS . $path;
            }
        }

        return '.' . DS . 'resque.php';
    }

    /**
     * Calling systeme tail command
     *
     * @param string $path Path to the file to tail
     *
     * @codeCoverageIgnore
     * @return void
     * @since  1.2.0
     */
    protected function tailCommand($path)
    {
        passthru('tail -f ' . escapeshellarg($path));
    }

    /**
     * Calling a shell command
     *
     * @param string $cmd Command to pass to system shell
     *
     * @codeCoverageIgnore
     * @return void
     * @since  1.2.0
     */
    protected function exec($cmd)
    {
        passthru($cmd);
    }

    /**
     * Send a signal to a process
     *
     * @param String $signal Signal to send
     * @param int    $pid    PID of the process
     *
     * @codeCoverageIgnore
     * @return array with the code and message returned by the command
     * @since  1.2.0
     */
    protected function kill($signal, $pid)
    {
        $output  = [];
        $message = exec(sprintf(($this->runtime['Default']['user'] !== $this->getProcessOwner() ? ('sudo -u ' . escapeshellarg($this->runtime['Default']['user'])) . ' ' : "") . '/bin/kill -%s %s 2>&1', $signal, $pid), $output, $code);

        return ['code' => $code, 'message' => $message];
    }

    /**
     * Check the content of the PID file created by the worker
     * to retrieve its process PID
     *
     * @param string $path Path to the PID file
     *
     * @codeCoverageIgnore
     * @return false|int The worker process ID, or false if error
     * @since  1.2.0
     */
    protected function checkStartedWorker($pidFile)
    {
        $pid = false;
        if (file_exists($pidFile) && false !== $pid = file_get_contents($pidFile)) {
            unlink($pidFile);

            return (int) $pid;
        }

        return false;
    }

    /**
     * Display a Dialog menu, and retrieve the user selection
     *
     * @param string $listTitle     Title of the menu dialog
     * @param string $selectMessage Select option message
     * @param array  $menuItems     The menu contents
     *
     * @codeCoverageIgnore
     * @return int The index in the menu that was selected
     * @since  1.2.0
     */
    protected function getUserChoice($listTitle, $selectMessage, $menuItems)
    {
        $menuOptions = new ezcConsoleMenuDialogOptions(
            [
                'text'       => $listTitle,
                'selectText' => $selectMessage,
                'validator'  => new DialogMenuValidator($menuItems),
            ]
        );

        $menuDialog = new ezcConsoleMenuDialog($this->output, $menuOptions);
        do {
            $menuDialog->display();
        } while ($menuDialog->hasValidResult() === false);

        return $menuDialog->getResult();
    }

    /**
     * Return the user owning the current process
     *
     * @codeCoverageIgnore
     * @return string Username of the current process owner if found, else false
     * @since 1.2.4
     */
    private function getProcessOwner()
    {
        if (function_exists('posix_getpwuid')) {
            $a = posix_getpwuid(posix_getuid());

            return $a['name'];
        } else {
            $user = trim(exec('whoami', $o, $code));
            if ($code === 0) {
                return $user;
            }

            return false;
        }

        return false;
    }

    public function getActiveWorkers(): array
    {
        return call_user_func(self::$Worker . '::all');
    }

    protected function enqueueJob($queue, $class, array $args): string
    {
        return call_user_func_array(self::$Resque . '::enqueue', [$queue, $class, $args]);
    }

    protected function setResqueBackend(): void
    {
        $args = [
            $this->runtime['Redis']['host'] . ':' . $this->runtime['Redis']['port'],
            $this->runtime['Redis']['database'],
            $this->runtime['Redis']['namespace'],
        ];
        if (!empty($this->runtime['Redis']['password'])) {
            $args[] = $this->runtime['Redis']['password'];
        }
        call_user_func_array(self::$Resque . '::setBackend', $args);
    }

    protected function initResqueStatus(): ResqueStatus
    {
        return new ResqueStatus(Resque::Redis());
    }

    protected function initResqueStats(): ResqueStats
    {
        return new ResqueStats(Resque::Redis());
    }

    protected function getResqueStat($value): int
    {
        return (int) Stat::get($value);
    }
}
