<?php
/*
 * @copyright  Copyright (C) 2019 Blue Flame Digital Solutions Limited / Phil Taylor. All rights reserved.
 * @author     Phil Taylor <phil@phil-taylor.com> and others, see README.md
 * @see        https://github.com/resquebundle/resque
 * @license    MIT
 */

namespace ResqueBundle\Resque\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Process\Process;

/**
 * Class StartWorkerCommand.
 */
class StartWorkerCommand extends Command
{
    private $params;

    public function __construct(string $name = null, ParameterBagInterface $params)
    {
        $this->params = $params;
        parent::__construct($name);
    }

    protected function configure()
    {
        $this
            ->setName('resque:worker-start')
            ->setDescription('Start a resque worker')
            ->addArgument('queues', InputArgument::REQUIRED, 'Queue names (separate using comma)')
            ->addOption('count', 'c', InputOption::VALUE_REQUIRED, 'How many workers to fork', 1)
            ->addOption('interval', 'i', InputOption::VALUE_REQUIRED, 'How often to check for new jobs across the queues', 5)
            ->addOption('foreground', 'f', InputOption::VALUE_NONE, 'Should the worker run in foreground')
            ->addOption('memory-limit', 'm', InputOption::VALUE_REQUIRED, 'Force cli memory_limit (expressed in Mbytes)');
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return int|void|null
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $env = [];

        // here to work around issues with pcntl and cli_set_process_title in PHP > 5.5
        if (version_compare(PHP_VERSION, '5.5.0') >= 0) {
            $env = $_SERVER;
            unset(
                $env['_'],
                $env['PHP_SELF'],
                $env['SCRIPT_NAME'],
                $env['SCRIPT_FILENAME'],
                $env['PATH_TRANSLATED'],
                $env['argv']
            );
        }

        $env['APP_INCLUDE'] = $this->params->get('resque.app_include');
        $env['COUNT']       = $input->getOption('count');
        $env['INTERVAL']    = $input->getOption('interval');
        $env['QUEUE']       = $input->getArgument('queues');

        // Allow Sentry.io integration
        if ($this->params->has('sentry.dsn')) {
            if ($sentryDSN = $this->params->get('sentry.dsn')) {
                $output->writeln('Enabling Sentry Reporting to DSN ' . $sentryDSN);
                $env['SENTRY_DSN'] = $sentryDSN;
            }
        }

        if (false !== getenv('APP_INCLUDE')) {
            $env['APP_INCLUDE'] = getenv('APP_INCLUDE');
        }

        $prefix = $this->params->get('resque.prefix');
        if (!empty($prefix)) {
            $env['PREFIX'] = $this->params->get('resque.prefix');
        }

        if ($input->getOption('verbose')) {
            $env['VVERBOSE'] = 1;
        }

        if ($input->getOption('quiet')) {
            unset($env['VERBOSE']);
        }

        $redisHost     = $this->params->get('resque.redis.host');
        $redisPort     = $this->params->get('resque.redis.port');
        $redisDatabase = $this->params->get('resque.redis.database');

        if (null != $redisHost && null != $redisPort) {
            $env['REDIS_BACKEND'] = $redisHost . ':' . $redisPort;
        }

        if (isset($redisDatabase)) {
            $env['REDIS_BACKEND_DB'] = $redisDatabase;
        }

        $opt = '';
        if (0 !== $m = (int)$input->getOption('memory-limit')) {
            $opt = sprintf('-d memory_limit=%dM', $m);
        }

        if (version_compare(PHP_VERSION, '5.4.0') >= 0) {
            $phpExecutable = PHP_BINARY;
        } else {
            $phpExecutable = PHP_BINDIR . '/php';
            if (\defined('PHP_WINDOWS_VERSION_BUILD')) {
                $phpExecutable = 'php';
            }
        }

        $workerCommand = strtr('%php% %opt% %dir%/resque', [
            '%php%' => $phpExecutable,
            '%opt%' => $opt,
            '%dir%' => __DIR__ . '/../bin',
        ]);

        if (!$input->getOption('foreground')) {
            $workerCommand = strtr('nohup %cmd% > %logs_dir%/resque.log 2>&1 & echo $!', [
                '%cmd%'      => $workerCommand,
                '%logs_dir%' => $this->params->get('kernel.logs_dir'),
            ]);
        }

        // In windows: When you pass an environment to CMD it replaces the old environment
        // That means we create a lot of problems with respect to user accounts and missing vars
        // this is a workaround where we add the vars to the existing environment.
        if (\defined('PHP_WINDOWS_VERSION_BUILD')) {
            foreach ($env as $key => $value) {
                putenv($key . '=' . $value);
            }
            $env = null;
        }

        $process = Process::fromShellCommandline($workerCommand, null, $env, null, null);

        if (!$input->getOption('quiet')) {
            $output->writeln(sprintf('Starting worker <info>%s</info>', $process->getCommandLine()));
        }

        // if foreground, we redirect output
        if ($input->getOption('foreground')) {
            $process->run(function($type, $buffer) use ($output) {
                $output->write($buffer);
            });
        } // else we recompose and display the worker id
        else {
            $process->run();
            $pid = trim($process->getOutput());

            if (\function_exists('gethostname')) {
                $hostname = gethostname();
            } else {
                $hostname = php_uname('n');
            }

            if (!$input->getOption('quiet')) {
                $output->writeln(sprintf('<info>Worker started</info> %s:%s:%s', $hostname, $pid, $input->getArgument('queues')));
            }
        }

        return 0;
    }
}
