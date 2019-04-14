<?php

/*
 * @copyright   2019 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\InstallBundle\Command;

use Mautic\InstallBundle\Install\InstallService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * CLI Command to install Mautic.
 * Class InstallCommand.
 */
class InstallCommand extends ContainerAwareCommand
{
    const CHECK_STEP    = 0;
    const DOCTRINE_STEP = 1;
    const USER_STEP     = 2;
    const EMAIL_STEP    = 3;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('mautic:install')
            ->setDescription('Installs Mautic')
            ->setHelp('This command allows you to trigger the install process.')
            ->addArgument(
                'site_url',
                InputArgument::REQUIRED,
                'Site URL.',
                null
            )
            ->addArgument(
                'step',
                InputArgument::OPTIONAL,
                'Install process start index. 0 for requirements check, 1 for database, 2 for admin, 3 for configuration. Each successful step will trigger the next until completion.',
                0
            )
            ->addOption(
                '--force',
                '-f',
                InputOption::VALUE_NONE,
                'Do not ask confirmation if recommendations triggered.',
                null
            )
            ->addOption(
                '--db_driver',
                null,
                InputOption::VALUE_REQUIRED,
                'Database driver.',
                'pdo_mysql'
            )
            ->addOption(
                '--db_host',
                null,
                InputOption::VALUE_REQUIRED,
                'Database host.',
                'localhost'
            )
            ->addOption(
                '--db_port',
                null,
                InputOption::VALUE_REQUIRED,
                'Database host.',
                3306
            )
            ->addOption(
                '--db_name',
                null,
                InputOption::VALUE_REQUIRED,
                'Database name.',
                'mautic'
            )
            ->addOption(
                '--db_user',
                null,
                InputOption::VALUE_REQUIRED,
                'Database user.',
                'mautic'
            )
            ->addOption(
                '--db_password',
                null,
                InputOption::VALUE_REQUIRED,
                'Database password.',
                null
            )
            ->addOption(
                '--db_table_prefix',
                null,
                InputOption::VALUE_REQUIRED,
                'Database tables prefix.',
                null
            )
            ->addOption(
                '--db_backup_tables',
                null,
                InputOption::VALUE_REQUIRED,
                'Backup database tables if they exist; otherwise drop them.',
                true
            )
            ->addOption(
                '--db_backup_prefix',
                null,
                InputOption::VALUE_REQUIRED,
                'Database backup tables prefix.',
                'bak_'
            )
            ->addOption(
                '--admin_firstname',
                null,
                InputOption::VALUE_REQUIRED,
                'Admin first name.',
                'Admin'
            )
            ->addOption(
                '--admin_lastname',
                null,
                InputOption::VALUE_REQUIRED,
                'Admin last name.',
                'Mautic'
            )
            ->addOption(
                '--admin_username',
                null,
                InputOption::VALUE_REQUIRED,
                'Admin username.',
                'admin'
            )
            ->addOption(
                '--admin_email',
                null,
                InputOption::VALUE_REQUIRED,
                'Admin email.',
                null
            )
            ->addOption(
                '--admin_password',
                null,
                InputOption::VALUE_REQUIRED,
                'Admin user.',
                null
            )
        ;
        parent::configure();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $container    = $this->getContainer();
        /** @var \Mautic\InstallBundle\Install\InstallService $installer */
        $installer = $container->get('mautic.install.service');

        $output->writeln([
            'Mautic Install',
            '==============',
            '',
        ]);

        // Check Mautic is not already installed
        if ($installer->checkIfInstalled()) {
            $output->writeln('Mautic already installed');

            return 0;
        }

        // Build objects to pass to the install service from local.php or command line options
        $output->writeln('Parsing options and arguments...');
        $options = $input->getOptions();

        $dbParams     = [];
        $adminOptions = [];
        foreach ($options as $opt => $value) {
            if ($value !== null) {
                if ((substr($opt, 0, 3) === 'db_')) {
                    $dbParams[substr($opt, 4)] = $value;
                } elseif ((substr($opt, 0, 6) === 'admin_')) {
                    $adminOptions[substr($opt, 7)] = $value;
                }
            }
        }

        $dbParams  = array_merge($dbParams, $installer->localConfigParameters());
        $siteUrl   = $input->getArgument('site_url');
        $allParams = array_merge($dbParams, ['site_url' => $siteUrl]);
        $step      = $input->getArgument('step');

        switch ($step) {
            default:
            case self::CHECK_STEP:
                $output->writeln('Checking installation requirements...');
                $messages = $this->stepAction($installer, ['site_url' => $siteUrl], $step);
                if (is_array($messages) && !empty($messages)) {
                    if (isset($messages['requirements']) && !empty($messages['requirements'])) {
                        // Stop install if requirements not met
                        $output->writeln('Missing requirements:');
                        $this->handleInstallerErrors($output, $messages['requirements']);
                        $output->writeln('Install canceled');

                        return -$step;
                    } elseif (isset($messages['optional']) && !empty($messages['optional'])) {
                        $output->writeln('Missing optional settings:');
                        $this->handleInstallerErrors($output, $messages['optional']);

                        if (!isset($options['force'])) {
                            // Ask user to confirm install when optional settings missing
                            $helper   = $this->getHelper('question');
                            $question = new ConfirmationQuestion('Continue with install anyway? ', false);

                            if (!$helper->ask($input, $output, $question)) {
                                return -$step;
                            }
                        }
                    }
                }
                $output->writeln('Ready to Install!');

            case self::DOCTRINE_STEP:
                $step = self::DOCTRINE_STEP;
                $output->writeln('Creating database...');
                $messages = $this->stepAction($installer, $dbParams, $step);
                if (is_array($messages) && !empty($messages)) {
                    $this->handleInstallerErrors($output, $messages);

                    return -$step;
                }

                $output->writeln('Creating schema...');
                $messages = $this->stepAction($installer, $dbParams, $step + .1);
                if (is_array($messages) && !empty($messages)) {
                    $this->handleInstallerErrors($output, $messages);

                    return -$step;
                }

                $output->writeln('Loading fixtures...');
                $messages = $this->stepAction($installer, $dbParams, $step + .2);
                if (is_array($messages) && !empty($messages)) {
                    $this->handleInstallerErrors($output, $messages);

                    return -$step;
                }

            case self::USER_STEP:
                $step = self::USER_STEP;
                $output->writeln('Creating admin user...');
                $messages = $this->stepAction($installer, $adminOptions, $step);
                if (is_array($messages) && !empty($messages)) {
                    $this->handleInstallerErrors($output, $messages);

                    return -$step;
                }

            case self::EMAIL_STEP:
                $step = self::EMAIL_STEP;
                $output->writeln('Email configuration and final steps...');
                $messages = $this->stepAction($installer, $allParams, $step);
                if (is_array($messages) && !empty($messages)) {
                    $this->handleInstallerErrors($output, $messages);

                    return -$step;
                }
        }

        $output->writeln([
            '',
            '================',
            'Install complete',
            '================',
        ]);

        return 0;
    }

    /**
     * Controller action for install steps.
     *
     * @param InstallService $installer The install process
     * @param array          $params    The install parameters
     * @param int            $index     The step number to process
     *
     * @return int|array|bool
     *
     * @throws \Exception
     */
    protected function stepAction(InstallService $installer, $params, $index = 0)
    {
        if (strpos($index, '.') !== false) {
            list($index, $subIndex) = explode('.', $index);
        }

        $step = $installer->getStep($index);

        $messages = false;
        switch ($index) {
            case self::CHECK_STEP:
                // Check installation requirements
                $step->site_url           = $params['site_url'];
                $messages                 = [];
                $messages['requirements'] = $installer->checkRequirements($step);
                $messages['optional']     = $installer->checkOptionalSettings($step);
                break;

            case self::DOCTRINE_STEP:
                if (!isset($subIndex)) {
                    // Install database
                    $messages = $installer->createDatabaseStep($step, $params);
                } else {
                    switch ((int) $subIndex) {
                        case 1:
                            // Install schema
                            $messages = $installer->createSchemaStep($params);
                            break;

                        case 2:
                            // Install fixtures
                            $messages = $installer->createFixturesStep($this->getContainer());
                            break;
                    }
                }
                break;

            case self::USER_STEP:
                // Create admin user
                $messages = $installer->createAdminUserStep($params);
                break;

            case self::EMAIL_STEP:
                // Save email configuration
                $messages = $installer->saveConfiguration($params, $step);
                break;
        }

        if (is_bool($messages) && $messages === true) {
            $siteUrl  = $params['site_url'];
            $messages = $installer->createFinalConfigStep($siteUrl);

            if (is_bool($messages) && $messages === true) {
                $installer->finalMigrationStep();
            }
        }

        return $messages;
    }

    /**
     * @param OutputInterface $output
     * @param array           $messages
     */
    private function handleInstallerErrors(OutputInterface $output, array $messages)
    {
        foreach ($messages as $type => $message) {
            $output->write("[$type] $message");
        }

        $output->writeln([
            '',
        ]);
    }
}
