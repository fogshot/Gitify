<?php
namespace modmore\Gitify\Command;

use modmore\Gitify\BaseCommand;
use modmore\Gitify\Mixins\DownloadModx;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * Class BuildCommand
 *
 * Installs a clean version of MODX.
 *
 * @package modmore\Gitify\Command
 */
class InstallModxCommand extends BaseCommand {
  use DownloadModx;

  public $loadConfig = false;
  public $loadMODX = false;

  protected function configure() {
    $this
      ->setName('modx:install')
      ->setAliases(array('install:modx'))
      ->setDescription('Downloads, configures and installs a fresh MODX installation. [Note: <info>install:modx</info> will be removed in 1.0, use <info>modx:install</info> instead]')
      ->addArgument(
        'version',
        InputArgument::OPTIONAL,
        'The version of MODX to install, in the format 2.3.2-pl. Leave empty or specify "latest" to install the last stable release.',
        'latest'
      )
      ->addOption(
        'download',
        'd',
        InputOption::VALUE_NONE,
        'Force download the MODX package even if it already exists in the cache folder.'
      )
      ->addOption(
        'user',
        'u',
        InputOption::VALUE_REQUIRED,
        'Specify the database user to use for the install script.'
      )
      ->addOption(
        'host',
        'H',
        InputOption::VALUE_REQUIRED,
        'Specify the database host to use for the install script.'
      )
      ->addOption(
        'name',
        'N', // somehow "n" seems to break the questions, so we'll use "N"
        InputOption::VALUE_REQUIRED,
        'Specify the name of the database to use.'
      )
      ->addOption(
        'password',
        'p',
        InputOption::VALUE_REQUIRED,
        'Specify the database password to use. Use "generate" to let a random one be created.'
      )
      ->addOption(
        'base',
        'b',
        InputOption::VALUE_REQUIRED,
        'Specify the base URL of the MODX install'
      )
      ->addOption(
        'language',
        'l',
        InputOption::VALUE_REQUIRED,
        'Specify the manager language to install.'
      )
      ->addOption(
        'manager-user',
        'U',
        InputOption::VALUE_REQUIRED,
        'Specify the manager user to be created by the install script.'
      )
      ->addOption(
        'manager-password',
        'P',
        InputOption::VALUE_REQUIRED,
        'Specify the password for the newly created manager user.'
      )
      ->addOption(
        'manager-email',
        'E',
        InputOption::VALUE_REQUIRED,
        'Specify the email address for the newly created manager user.'
      );
  }

  /**
   * Runs the command.
   *
   * @param InputInterface $input
   * @param OutputInterface $output
   * @return int
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    $version = $this->input->getArgument('version');
    $forced = $this->input->getOption('download');

    if (!$this->getMODX($version, $forced)) {
      return 1; // exit
    }

    // Create the XML config
    $config = $this->createMODXConfig();

    // Variables for running the setup
    $tz = date_default_timezone_get();
    $wd = GITIFY_WORKING_DIR;
    $output->writeln("Running MODX Setup...");

    // Actually run the CLI setup
    exec("php -d date.timezone={$tz} {$wd}setup/index.php --installmode=new --config={$config}", $setupOutput);
    $output->writeln("<comment>{$setupOutput[0]}</comment>");

    // Try to clean up the config file
    if (!unlink($config)) {
      $output->writeln("<warning>Warning:: could not clean up the setup config file, please remove this manually.</warning>");
    }

    $output->writeln('Done! ' . $this->getRunStats());
    return 0;
  }

  /**
   * Asks the user to complete a bunch of details and creates a MODX CLI config xml file
   */
  protected function createMODXConfig() {
    $directory = GITIFY_WORKING_DIR;

    // optional parameters that replace interactive user input
    $dbName = $this->input->getOption('name');
    $dbUser = $this->input->getOption('user');
    $dbPass = $this->input->getOption('password');
    $host = $this->input->getOption('host');
    $baseUrl = $this->input->getOption('base');
    $language = $this->input->getOption('language');
    $managerUser = $this->input->getOption('manager-user');
    $managerPass = $this->input->getOption('manager-password');
    $managerEmail = $this->input->getOption('manager-email');

    // Creating config xml to install MODX with
    if (empty($dbName) || empty($dbUser) || empty($dbPass) || empty($host) || empty($baseUrl) || empty($language) || empty($managerUser) || empty($managerPass) || empty($managerEmail)) {
      $helper = $this->getHelper('question');
      $this->output->writeln("Please complete following details to install MODX. Leave empty to use the [default].");
    }

    if (empty($dbName)) {
      $defaultDbName = basename(GITIFY_WORKING_DIR);
      $question = new Question("Database Name [{$defaultDbName}]: ", $defaultDbName);
      $dbName = $helper->ask($this->input, $this->output, $question);
    } else {
      $this->output->writeln("DB Name:          " . $dbName);
    }

    if (empty($dbUser)) {
      $question = new Question('Database User [root]: ', 'root');
      $dbUser = $helper->ask($this->input, $this->output, $question);
    } else {
      $this->output->writeln("DB User:          " . $dbUser);
    }

    if (empty($dbPass)) {
      $question = new Question('Database Password: ');
      $question->setHidden(true);
      $dbPass = $helper->ask($this->input, $this->output, $question);
    } else {
      $this->output->writeln("DB Password:      " . $dbPass);
    }

    if (empty($host)) {
      $question = new Question('Hostname [' . gethostname() . ']: ', gethostname());
      $host = $helper->ask($this->input, $this->output, $question);
      $host = rtrim(trim($host), '/');
    } else {
      $this->output->writeln("DB Host:          " . $host);
    }

    if (empty($baseUrl)) {
      $defaultBaseUrl = '/';
      $question = new Question('Base URL [' . $defaultBaseUrl . ']: ', $defaultBaseUrl);
      $baseUrl = $helper->ask($this->input, $this->output, $question);
      $baseUrl = '/' . trim(trim($baseUrl), '/') . '/';
      $baseUrl = str_replace('//', '/', $baseUrl);
    } else {
      $this->output->writeln("Base URL:         " . $baseUrl);
    }

    if (empty($language)) {
      $question = new Question('Manager Language [en]: ', 'en');
      $language = $helper->ask($this->input, $this->output, $question);
    } else {
      $this->output->writeln("Language:         " . $language);
    }

    if (empty($managerUser)) {
      $defaultMgrUser = basename(GITIFY_WORKING_DIR) . '_admin';
      $question = new Question('Manager User [' . $defaultMgrUser . ']: ', $defaultMgrUser);
      $managerUser = $helper->ask($this->input, $this->output, $question);
    } else {
      $this->output->writeln("Manager User:     " . $managerUser);
    }

    if (empty($managerPass)) {
      $question = new Question('Manager User Password [generated]: ', 'generate');
      $question->setHidden(true);
      $question->setValidator(function ($value) {
        call_user_func(array(__NAMESPACE__ . "\InstallModxCommand", "validatePass"), $value);
      });
      $managerPass = $helper->ask($this->input, $this->output, $question);
    } else {
      $this->output->writeln("Manager Password: " . $managerPass);
      validatePass($managerPass);
    }

    if ('generate' == $managerPass) {
      $managerPass = substr(str_shuffle(md5(microtime(true))), 0, rand(8, 15));
      $this->output->writeln("<info>Generated Manager Password: {$managerPass}</info>");
    }

    if (empty($managerEmail)) {
      $question = new Question('Manager Email: ');
      $managerEmail = $helper->ask($this->input, $this->output, $question);
    } else {
      $this->output->writeln("Manager Email:    " . $managerEmail);
    }

    $configXMLContents = "<modx>
            <database_type>mysql</database_type>
            <database_server>localhost</database_server>
            <database>{$dbName}</database>
            <database_user>{$dbUser}</database_user>
            <database_password>{$dbPass}</database_password>
            <database_connection_charset>utf8</database_connection_charset>
            <database_charset>utf8</database_charset>
            <database_collation>utf8_general_ci</database_collation>
            <table_prefix>modx_</table_prefix>
            <https_port>443</https_port>
            <http_host>{$host}</http_host>
            <cache_disabled>0</cache_disabled>
            <inplace>1</inplace>
            <unpacked>0</unpacked>
            <language>{$language}</language>
            <cmsadmin>{$managerUser}</cmsadmin>
            <cmspassword>{$managerPass}</cmspassword>
            <cmsadminemail>{$managerEmail}</cmsadminemail>
            <core_path>{$directory}core/</core_path>
            <context_mgr_path>{$directory}manager/</context_mgr_path>
            <context_mgr_url>{$baseUrl}manager/</context_mgr_url>
            <context_connectors_path>{$directory}connectors/</context_connectors_path>
            <context_connectors_url>{$baseUrl}connectors/</context_connectors_url>
            <context_web_path>{$directory}</context_web_path>
            <context_web_url>{$baseUrl}</context_web_url>
            <remove_setup_directory>1</remove_setup_directory>
        </modx>";

    $fh = fopen($directory . 'config.xml', "w+");
    fwrite($fh, $configXMLContents);
    fclose($fh);

    return $directory . 'config.xml';
  }

  public function validatePass($value) {
    if (empty($value) || strlen($value) < 8) {
      throw new \RuntimeException(
        'Please specify a password of at least 8 characters to continue.'
      );
    }
    return $value;
  }

}
