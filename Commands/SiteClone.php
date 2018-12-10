<?php

namespace Terminus\Commands;
require_once __DIR__ . '/../vendor/autoload.php';

use Terminus\Collections\Sites;
use Terminus\Config;
use Terminus\Exceptions\TerminusException;
use Terminus\Utils;
use SiteClone\Custom\SiteCloneCustomTrait;

/**
 * Class CloneSiteCommand
 * @package Terminus\Commands
 * @command site
 */
class SiteCloneCommand extends TerminusCommand {

  use SiteCloneCustomTrait;

  private $version = '0.1.1';
  private $compatible_terminus_version = '0.13.3';

  private $default_envs = ['dev', 'test', 'live'];

  protected $sites;
  //TODO: Would be nice to programmatically determine command name (set in annotations).
  private $deploy_note = "Deployed by 'terminus site clone'";

  /**
   * Create a new site which duplicates the environments, code and content of an existing Pantheon site.
   *
   * @param array $options Options to construct the command object
   */
  public function __construct(array $options = []) {
    $options['require_login'] = TRUE;
    parent::__construct($options);

    date_default_timezone_set('UTC');
    $this->command_start_time = time();

    $this->sites = new Sites();

    if (Utils\isWindows()) {
      //FIXME: need windows to test
      $this->clone_path = 'c:\TEMP'; // ???
    }
    else {
      $this->clone_path = '/tmp';
    }
  }

  /**
   * Create a new site which duplicates the environments, code and content of an existing Pantheon site.
   *
   * [--version]
   * : Show plugin version and compatible Terminus version.
   *
   * [--source-site=<site>]
   * : Name of the existing site to be cloned. (Required, unless --version is used.)
   *
   * [--target-site=<site>]
   * : Name of the new site which will be a copy of the source site. (Required, unless --version or one of --target-site-[prefix|suffix] is used.)
   *
   * [--target-site-prefix=<prefix>]
   * : Target site name will be source site name prefixed with this string.
   *
   * [--target-site-suffix=<suffix>]
   * : Target site name will be source site name suffixed with this string.
   *
   * [--target-site-org=<organization>]
   * : The organization (name or ID) in which to create the target site. Defaults to source site organization.
   *
   * [--target-site-upstream=<upstream>]
   * : The upstream repository (name or ID) to use for the target site. Defaults to source site upstream.
   *
   * [--target-site-git-depth=<number>]
   * : The value to assign '--depth' when git cloning. (Default: No depth. Get all commits.)
   *
   * [--source-site-backup]
   * : Create fresh backups in all initialized source site environments before proceeding with clone.
   *
   * [--source-site-git-depth=<number>]
   * : The value to assign '--depth' when git cloning. (Default: No depth. Get all commits.)
   *
   * [--no-custom=<functionname>]
   * : Skip custom transformation functions. (Separate multiple function names with commas: --no-custom=transformCode_002,transformContent_001)
   *
   * [--debug-git]
   * : Do not clean up the git working directories.
   *
   * @subcommand clone
   *
   * @param array $args Array of main arguments
   * @param array $assoc_args Array of associative arguments
   * @return null
   * @throws \Terminus\Exceptions\TerminusException
   */
  public function siteClone($args, $assoc_args) {

    if (isset($assoc_args['version'])) {
      $labels = [
        'version' => 'Plugin (site clone) Version',
        'terminus_version' => 'Compatible Terminus Version',
      ];
      $this->output()->outputRecord(
        [
          'version' => $this->version,
          'terminus_version' => $this->compatible_terminus_version,
        ],
        $labels
      );

      return TRUE;
    }

    // Validate options
    if (!isset($assoc_args['source-site'])) {
      throw new TerminusException("The '--source-site' option is required.");
    }

    $target_site_name = $this->targetSiteName($assoc_args);
    if (empty($target_site_name)) {
      throw new TerminusException("At least one of --target-site-name, --target-site-prefix or --target-site-suffix is required. Also, the target site name must be different than the source site name.");
    }

    // Make sure target site doesn't exist.
    try {
      $existing_target_site = $this->sites->get($target_site_name);
    }
    catch (TerminusException $e) {
      // Good, the site doesn't exist -- do nothing.
    }

    if (isset($existing_target_site)) {
      throw new TerminusException("The target site '{target}' already exists.  Please choose another name.", ['target' => $target_site_name]);
    }

    // Validate source site
    try {
      $source_site = $this->sites->get($assoc_args['source-site']);
    }
    catch (TerminusException $e) {
      throw new TerminusException("The source site '{source}' doesn't exist. Please choose an existing site.", ['source' => $assoc_args['source-site']]);
    }

    // Make sure there is a 'git' command.
    if (!$this->doExec('git --version')) {
      throw new TerminusException("'git' was not found in your path. You must remedy this before using this command.");
    }

    $source_site_environments_info = $this->getEnvironmentsInfo($source_site);
    $source_site_environments = array_column($source_site_environments_info, "initialized", "id");

    $source_site_name = $assoc_args['source-site'];
    $source_clone_path = $this->clone_path . DIRECTORY_SEPARATOR . $source_site_name;
    $target_clone_path = $this->clone_path . DIRECTORY_SEPARATOR . $target_site_name;

    // Make sure source site has the backups we'll need.
    if (isset($assoc_args['source-site-backup'])) {
      // User asked to refresh backups.
      $this->backupInitializedEnvironments($source_site, $source_site_environments, $this->default_envs);
    }
    else {
      // Check existing backups.
      $problem_backups = $this->validateBackups($this->getBackupsInfo($source_site, $source_site_environments), $this->default_envs);
      $problem_backups = array_merge_recursive($problem_backups['missing'], $problem_backups['stale']);
      if (count($problem_backups)) {
        foreach ($problem_backups as $env => $elements) {
          foreach ($elements as $element) {
            $this->log()
              ->info("The backup for Site: {source_site} Env: {env} Element: {element} is either missing or stale. Creating a new backup.", [
                'source_site' => $source_site_name,
                'env' => $env,
                'element' => $element
              ]);
            $this->createBackup($source_site, $env, $element);
          }
        }
      }
    }

    // Done with validation! Get down to business. //

    // Set target site upstream
    if (array_key_exists('target-site-upstream', $assoc_args)) {
      $target_site_upstream = $assoc_args['target-site-upstream'];
    }
    else {
      $target_site_upstream = $source_site->upstream->id;
    }

    if (empty($target_site_upstream)) {
      throw new TerminusException("The upstream for this site is null.");
    }

    // Set target site org
    if (array_key_exists('target-site-org', $assoc_args)) {
      $target_site_org = $assoc_args['target-site-org'];
    }
    else {
      $target_site_org = $source_site->get('organization');
    }

    //Create the target site
    $this->log()->info("Creating the target site...");

    if ($this->helpers->launch->launchSelf(
        [
          'command' => 'sites',
          'args' => ['create',],
          'assoc_args' => [
            'label' => $target_site_name,
            'site' => $target_site_name,
            'org' => $target_site_org,
            'upstream' => $target_site_upstream
          ],
        ]
      ) != 0
    ) {
      throw new TerminusException("Failed to create target site.");
    }

    // Target site object for use later.
    $target_site = $this->sites->get($target_site_name);

    // Make sure the new site is in git mode.
    $this->setConnectionMode($target_site, "git");

    // Git clone the code for the source and target sites
    $this->log()->info("Downloading site code...");

    foreach ([
               'source' => $source_site_name,
               'target' => $target_site_name
             ] as $key => $site) {

      if (array_key_exists($key . '-site-git-depth', $assoc_args)) {
        $depth = $assoc_args[$key . '-site-git-depth'];
      }
      else {
        $depth = '';
      }

      $this->gitCloneSite($site, $depth);
    }

    $this->log()
      ->info("Merging {source} code into {target}", [
        'source' => $source_site_name,
        'target' => $target_site_name
      ]);

    if (!$this->doExec("cd $target_clone_path && git pull -X theirs ../$source_site_name master --no-squash")) {
      throw new TerminusException("Failed to merge {source} code into {target}", [
        'source' => $source_site_name,
        'target' => $target_site_name
      ]);
    }

    // Find the latest commit sha for the source site.
    $output = [];
    if (!$this->doExec("cd $source_clone_path && git log --pretty=format:%h -1", "", $output)) {
      throw new TerminusException("Failed to find latest commit for {source} repository", ['source' => $source_clone_path]);
    }
    // Reset the target site to the latest commit sha for the source site.
    if (!$this->gitResetRepository($target_clone_path, $output[0])) {
      throw new TerminusException("Failed to reset {target} repository to latest commit.", ['target' => $target_clone_path]);
    }

    // Deploy to Dev
    if (!$this->doExec("cd $target_clone_path && git push -f", TRUE)) {
      throw new TerminusException("Failed to recreate code for dev environment.");
    }

    //Apply user code transformations
    $this->callCustomMethods('transformCode', $target_site, 'dev', $assoc_args);

    // Copy code from source environments to target environments, preserving pending commits, if they exist.
    $this->recreateEnvironmentCode($source_site, $source_site_environments, $target_site_name);

    // Copy content (db, files) from source environments to target environments.
    $this->recreateEnvironmentContent($source_site, $source_site_environments, $target_site, $assoc_args);

    if (!isset($assoc_args['debug-git'])) {
      $this->log()->info("Cleaning up local git working directories.");
      foreach ([$source_clone_path, $target_clone_path] as $path) {
        //FIXME: Windows compatibility... Better to do recursive delete with php.
        if ((!$this->doExec("rm -rf $path" . DIRECTORY_SEPARATOR . '.git')  ||
          !$this->doExec("rm -rf $path"))
        ) {
          $this->log()->info("Failed to remove {path}", ['path' => $path]);
        }
      }
    }
    else {
      $this->log()
        ->warning("Debug mode. Git working directories not removed: {src}, {target}", [
          'src' => $source_clone_path,
          'target' => $target_clone_path
        ]);
    }

    $this->output()->outputValue("\nSOURCE SITE URLs (for reference)");
    $this->output()->outputRecord($this->getSiteUrls($source_site, $this->default_envs));

    $this->output()->outputValue("\nTARGET SITE URLs");
    $this->output()->outputRecord($this->getSiteUrls($target_site, $this->default_envs));
  }

  /**
   * @param $methods
   * @return array
   */
  protected function getCustomMethods($methods) {
    $custom_methods = [];
    $allowed_types = ["transformContent", "transformCode"];

    sort($methods);
    foreach ($methods as $method) {
      foreach ($allowed_types as $type) {
        if (strpos($method, $type) !== FALSE) {
          $custom_methods[$type][] = $method;
        }
      }
    }

    return $custom_methods;
  }

  /**
   * @param $type
   * @param \Terminus\Models\Site $site
   * @param $env
   * @param $assoc_args
   */
  protected function callCustomMethods($type, \Terminus\Models\Site $site, $env, $assoc_args) {
    $custom_methods = $this->getCustomMethods(get_class_methods($this));

    if (array_key_exists('no-custom', $assoc_args)) {
      $skip_functions = explode(',', $assoc_args['no-custom']);
    }
    else {
      $skip_functions = [];
    }

    if (array_key_exists($type, $custom_methods)) {
      foreach ($custom_methods[$type] as $method) {
        if (!in_array($method, $skip_functions)) {
          $this->$method($site, $env, $assoc_args);
        }
        else {
          $this->log()
            ->info("Custom function '{func}' skipped as requested.", ['func' => $method]);
        }
      }
    }
  }

  /**
   * Generate target site name.
   *
   * @param $assoc_args
   * @return string
   */
  protected function targetSiteName($assoc_args) {
    if (array_key_exists('target-site', $assoc_args)) {
      if ($assoc_args['target-site'] == $assoc_args['source-site']) {
        return NULL;
      }
      return $assoc_args['target-site'];
    }

    $target_site = $assoc_args['source-site'];

    if (array_key_exists('target-site-prefix', $assoc_args)) {
      $target_site = $assoc_args['target-site-prefix'] . '-' . $target_site;
    }

    if (array_key_exists('target-site-suffix', $assoc_args)) {
      $target_site .= '-' . $assoc_args['target-site-suffix'];
    }


    return $target_site;
  }

  /**
   * @param $clone_path
   * @param string $commit_message
   * @return bool
   */
  public function gitAddCommitPush($clone_path, $commit_message = "") {
    if (!($this->doExec("cd $clone_path && git add -A", TRUE) &&
      $this->doExec("cd $clone_path && git commit -m \"$commit_message\"", TRUE) &&
      $this->doExec("cd $clone_path && git push origin master", TRUE))
    ) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * @param $site_name
   * @param string $depth
   * @return bool
   */
  protected function gitCloneSite($site_name, $depth = '') {

    // If the site has already been cloned, 'git pull'
    $clone_path = $this->clone_path . DIRECTORY_SEPARATOR . $site_name;
    if (is_dir($clone_path . DIRECTORY_SEPARATOR . '.git')) {
      $this->log()
        ->info("Found {clone_path}. Attempting 'git pull'.", ['clone_path' => $clone_path]);

      if (!$this->doExec("cd $clone_path && git pull")) {
        throw new TerminusException("Failed to git pull {site} to {path}. (You might want to investigate/clean up that path.)", ['site' => $site_name, 'path' => $clone_path]);
      }
      else {
        return TRUE;
      }
    }

    $depth_option = "";
    if (!empty($depth)) {
      $depth_option = " --depth $depth";
    }

    $git_command = $this->getConnectionInfo($site_name, "dev", "git_command");
    $git_command = preg_replace("/ $site_name\$/", " " . $this->clone_path . DIRECTORY_SEPARATOR . $site_name, $git_command);
    $this->log()
      ->info("Git cloning{depth_option} {site}...", ["site" => $site_name, "depth_option" => $depth_option]);

    if (!$this->doExec($git_command . $depth_option)) {
      throw new TerminusException("Failed to git clone {site} to {path}. (You might want to investigate/clean up that path.)", ['site' => $site_name, 'path' => $clone_path]);
    }

    return TRUE;
  }

  /**
   * @param $site_name
   * @param $env
   * @param string $field
   * @return bool
   */
  protected function getConnectionInfo($site_name, $env, $field = "") {
    $site = $this->sites->get($site_name);
    $environment = $site->environments->get($env);
    $info = $environment->connectionInfo();

    if (!empty($field)) {
      return $info[$field];
    }
    else {
      return "";
    }
  }

  /**
   * @param \Terminus\Models\Site $site
   * @return array
   */
  protected function getEnvironmentsInfo(\Terminus\Models\Site $site) {
    $data = array_map(
      function ($env) {
        return $env->serialize();
      },
      $site->environments->all()
    );
    return $data;
  }

  /**
   * @param \Terminus\Models\Site $site
   * @param $environments
   * @return array
   */
  protected function getEnvironmentsDeployableCommits(\Terminus\Models\Site $site, $environments) {
    $deployable_commits = [];

    foreach ($environments as $environment => $initialized) {
      // Skip dev and multidev environments.
      if ($environment == "dev" || !in_array($environment, ['test', 'live'])) {
        continue;
      }
      if ($initialized == "true") {
        $env = $site->environments->get($environment);
        $deployable_commits[$environment] = $env->countDeployableCommits();
      }
    }

    return $deployable_commits;
  }

  /**
   * @param \Terminus\Models\site $source_site
   * @param $source_site_environments
   * @param $target_site_name
   * @throws \Terminus\Exceptions\TerminusException
   */
  protected function recreateEnvironmentCode(\Terminus\Models\Site $source_site, $source_site_environments, $target_site_name) {
    $target_clone_path = $this->clone_path . DIRECTORY_SEPARATOR . $target_site_name;

    // Determine if there are undeployed commits for each environment.
    $deployable_commits = $this->getEnvironmentsDeployableCommits($source_site, $source_site_environments);

    // If live is initialized and there are no pending commits in test or live, we can deploy the dev code all the way to live.
    if (($source_site_environments['live'] == "true") && ($deployable_commits['live'] == 0) && ($deployable_commits['test'] == 0)) {
      if (!($this->doExec("cd $target_clone_path && git push", TRUE) &&
        $this->deployToEnvironment($target_site_name, "live", $this->deploy_note)
      )
      ) {
        throw new TerminusException("Failed to recreate code for target site environments.");
      }
    }
    // If test is initialized and there are no pending commits in test, we can deploy the dev code all to test.
    elseif (($source_site_environments['test'] == "true") && ($deployable_commits['test'] == 0)) {
      if (!($this->doExec("cd $target_clone_path && git push", TRUE) &&
        $this->deployToEnvironment($target_site_name, "test", $this->deploy_note)
      )
      ) {
        throw new TerminusException("Failed to recreate code for target site environments.");
      }
    }
    else {
      // There are pending commits in one or more environments.
      $this->recreateEnvironmentsWithPendingCommits($target_site_name, $deployable_commits);
    }

  }

  /**
   * @param $site_name
   * @param array $deployable_commits
   * @return bool
   * @throws \Terminus\Exceptions\TerminusException
   */
  protected function recreateEnvironmentsWithPendingCommits($site_name, array $deployable_commits) {

    $clone_path = $this->clone_path . DIRECTORY_SEPARATOR . $site_name;

    // First make a copy of the repo's original state.
    if (!($this->doExec("cd $clone_path && git checkout -b original", TRUE) &&
      $this->doExec("cd $clone_path && git checkout master", TRUE))
    ) {
      throw new TerminusException("Failed to create branch with original copy of code.");
    }

    // LIVE: Exists and has deployable commits.
    if (array_key_exists('live', $deployable_commits) && $deployable_commits['live'] > 0) {
      // git reset HEAD~0 returns exit status of 0
      $number_commits = $deployable_commits['live'] + $deployable_commits['test'];
      if (!($this->doExec("cd $clone_path && git reset --hard HEAD~$number_commits", TRUE) &&
        $this->doExec("cd $clone_path && git push -f", TRUE) &&
        //deploy to test and live
        $this->deployToEnvironment($site_name, "live", $this->deploy_note)
      )
      ) {
        throw new TerminusException("Failed to recreate code for live environment.");
      }

      // reset master branch to the copy we created above.
      if (!$this->doExec("cd $clone_path && git merge original", TRUE)) {
        throw new TerminusException("Failed to reset dev to original state.");
      }
    }

    // TEST: Exists and has deployable commits.
    if (array_key_exists('test', $deployable_commits) && $deployable_commits['test'] > 0) {

      if (!($this->doExec("cd $clone_path && git reset --hard HEAD~" . $deployable_commits['test'], TRUE) &&
        $this->doExec("cd $clone_path && git push -f", TRUE)
      )
      ) {
        throw new TerminusException("Failed to recreate code for test environment.");
      }

      if (array_key_exists('live', $deployable_commits) && $deployable_commits['live'] == 0) {
        // Live and Test are at the same commit
        $deploy_to = 'live';
      }
      else {
        $deploy_to = 'test';
      }

      if (!$this->deployToEnvironment($site_name, $deploy_to, $this->deploy_note)) {
        throw new TerminusException("Failed to deploy to {env}.", ['env' => $deploy_to]);
      }

      // reset master branch to the copy we created above because dev has commits that are pending in test.
      if (!$this->doExec("cd $clone_path && git merge original", TRUE)) {
        throw new TerminusException("Failed to reset dev to original state.");
      }

      // DEV: Push commits to dev that are pending in test.
      if (!$this->doExec("cd $clone_path && git push", TRUE)
      ) {
        throw new TerminusException("Failed to push commit to dev environment.");
      }

    }
    // TEST: Exists and does NOT have deployable commits.
    elseif (array_key_exists('test', $deployable_commits) && $deployable_commits['test'] == 0) {
      // No pending commits in Test -- Test is at the same commit as dev
      if (!($this->doExec("cd $clone_path && git push", TRUE) &&
        // push commits to dev and test
        $this->deployToEnvironment($site_name, "test", $this->deploy_note)
      )
      ) {
        throw new TerminusException("Failed to recreate code for test environment.");
      }
    }
    else {
      // TEST: Does not exist.
      // Push to dev.
      if (!($this->doExec("cd $clone_path && git push", TRUE))) {
        throw new TerminusException("Failed to recreate code for dev environment.");
      }

    }

    return TRUE;
  }

  /**
   * @param \Terminus\Models\Site $source_site
   * @param $source_site_environments
   * @param \Terminus\Models\Site $target_site
   * @param $assoc_args
   */
  protected function recreateEnvironmentContent(\Terminus\Models\Site $source_site, $source_site_environments, \Terminus\Models\Site $target_site, $assoc_args) {

    foreach ($source_site_environments as $environment => $initialized) {
      if ($initialized != "true") {
        continue;
      }

      //for now skip multidev environments
      if (!in_array($environment, $this->default_envs)) {
        continue;
      }

      $this->loadContentFromBackup($source_site, $environment, $target_site->get('name'), $environment, [
        'database',
        'files'
      ]);

      // Content transformations.
      // Core transformations
      // TODO: Disable mail? Since users may have various preferences re strategy, require them to do it in the content transformations, for now.
      // Apply user content transformations
      $this->callCustomMethods('transformContent', $target_site, $environment, $assoc_args);

      // Clear caches
      $this->clearCache($target_site, $environment);
    }

  }

  /**
   * @param \Terminus\Models\Site $source_site
   * @param $source_env
   * @param $target_site_name
   * @param $target_env
   * @param array $elements
   * @return bool
   */
  protected function loadContentFromBackup(\Terminus\Models\Site $source_site, $source_env, $target_site_name, $target_env, array $elements = [
    'code',
    'database',
    'files'
  ]) {

    $source_site_name = $source_site->get('name');

    foreach ($elements as $element) {
      $message_context = [
        'source_site' => $source_site_name,
        'source_env' => $source_env,
        'target_site' => $target_site_name,
        'target_env' => $target_env,
        'element' => $element
      ];
      $url = $this->getLatestBackupUrl($source_site, $source_env, $element);

      if (is_null($url)) {
        $this->log()
          ->error("Failed to find backup for site: {site} environment: {env} element: {element}", [
            'site' => $source_site_name,
            'env' => $source_env,
            'element' => $element
          ]);
        return FALSE;
      }

      $this->log()
        ->info("Importing content: {source_site} {source_env} {element} to {target_site} {target_env} {element}.", $message_context);

      if ($this->helpers->launch->launchSelf(
          [
            'command' => 'site',
            'args' => ['import-content'],
            'assoc_args' => [
              'site' => $target_site_name,
              'env' => $target_env,
              'url' => $url,
              'element' => $element
            ],
          ]
        ) != 0
      ) {
        $this->log()
          ->error("Failed to import {source_site} {source_env} {element} to {target_site} {target_env} {element}.", $message_context);
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * @param \Terminus\Models\Site $site
   * @param $env
   * @param $element
   */
  protected function createBackup(\Terminus\Models\Site $site, $env, $element) {
    $environment = $site->environments->get($env);

    $data = [
      'element' => $element,
    ];
    $workflow = $environment->backups->create($data);
    $workflow->wait();
    $this->workflowOutput($workflow);
  }

  /**
   * @param \Terminus\Models\Site $site
   * @param array $site_environments
   * @param array $elements
   * @param array $filter_envs
   */
  protected function backupInitializedEnvironments(\Terminus\Models\Site $site, array $site_environments, array $elements = ["all"], array $filter_envs = []) {
    $site_name = $site->get("name");

    foreach ($site_environments as $env => $initialized) {
      if ($initialized == 'true') {
        if (count($filter_envs) && !in_array($env, $filter_envs)) {
          continue;
        }
        foreach ($elements as $element) {
          $this->log()
            ->info("As requested creating a new backup of Site: {site} Environment: {env} Element: {element}", [
              'site' => $site_name,
              'env' => $env,
              'element' => $element
            ]);
          $backups[$env][$element] = $this->createBackup($site, $env, $element);
        }
      }
    }
  }

  /**
   * @param \Terminus\Models\Site $site
   * @param array $site_environments Keys: dev, test, live. Values: 'true' or 'false' (strings) indicating environment initialization state.
   * @param array $elements
   * @return array
   */
  protected function getBackupsInfo(\Terminus\Models\Site $site, array $site_environments, array $elements = [
    'database',
    'files'
  ]) {
    $backups = [];

    foreach ($site_environments as $env => $initialized) {
      if ($initialized == 'true') {
        foreach ($elements as $element) {
          $backups[$env][$element] = $this->getBackups($site, $env, $element);
        }
      }
    }
    return $backups;
  }

  /**
   * @param \Terminus\Models\Site $site
   * @param $env
   * @param $element
   * @return mixed
   */
  protected function getLatestBackupUrl(\Terminus\Models\Site $site, $env, $element) {
    $backups = $this->getBackups($site, $env, $element);
    if (count($backups)) {
      $latest_backup = array_shift($backups);
      return $latest_backup->getUrl();
    }

    return "";
  }

  /**
   * @param array $backups
   * @param array $filter_envs
   * @param array $filter_elements
   * @return array
   */
  protected function validateBackups(array $backups, array $filter_envs = [], array $filter_elements = []) {

    $missing = [];
    $stale = [];

    foreach ($backups as $env => $elements) {
      if (count($filter_envs) && !in_array($env, $filter_envs)) {
        continue;
      }
      foreach ($elements as $element => $data) {
        if (count($filter_elements) && !in_array($env, $filter_elements)) {
          continue;
        }
        if (!count($data)) {
          $missing[$env][] = $element;
        }
        elseif ($this->backupIsStale($data[0])) {
          $stale[$env][] = $element;
        }
      }
    }

    return ['missing' => $missing, 'stale' => $stale];
  }

  /**
   * @param \Terminus\Models\Backup $backup
   * @param int $max_age
   * @return bool
   */
  protected function backupIsStale(\Terminus\Models\Backup $backup, $max_age = 60 * 60 * 48) {
    $backup_finish_time = $backup->get('finish_time');
    if ($this->command_start_time - $backup_finish_time > $max_age) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * @param \Terminus\Models\Site $site
   * @param $env
   * @param $element
   * @return mixed
   */
  protected function getBackups(\Terminus\Models\Site $site, $env, $element) {
    $env = $site->environments->get($env);
    $backups = $env->backups->getFinishedBackups($element);

    return $backups;
  }

  /**
   * @param \Terminus\Models\Site $site
   * @param $mode
   * @param string $env
   */
  protected function setConnectionMode(\Terminus\Models\Site $site, $mode, $env = "dev") {
    $environment = $site->environments->get($env);
    $workflow = $environment->changeConnectionMode($mode);
    if (is_string($workflow)) {
      $this->log()->info($workflow);
    }
    else {
      $workflow->wait();
      $this->workflowOutput($workflow);
    }
  }

  /**
   * @param \Terminus\Models\Site $site
   * @param $env
   */
  protected function clearCache(\Terminus\Models\Site $site, $env) {
    $this->log()->info("Clearing site cache: Site: {site} Environment: {env}", ['site' => $site->get("name"), 'env' => $env]);
    $environment = $site->environments->get($env);
    $workflow    = $environment->clearCache();
    $workflow->wait();
    $this->workflowOutput($workflow);
  }

  /**
   * @param $site
   * @param $to_env
   * @param $note
   * @return bool
   */
  protected function deployToEnvironment($site, $to_env, $note) {
    if ($to_env == 'dev') {
      return FALSE;
    }
    elseif ($to_env == 'test') {
      $envs = ['test'];
    }
    else {
      $envs = ['test', 'live'];
    }

    foreach ($envs as $env) {
      $this->log()->info("Deploying {site} code to {env}.", [
        'site' => $site,
        'env' => $env
      ]);

      if ($this->helpers->launch->launchSelf(
          [
            'command' => 'site',
            'args' => ['deploy'],
            'assoc_args' => [
              'site' => $site,
              'env' => $env,
              'note' => $note
            ],
          ]
        ) != 0
      ) {
        $this->log()
          ->error("Failed to deploy {site} to {env}.", [
            'site' => $site,
            'env' => $to_env
          ]);
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * @param $clone_path
   * @param $sha
   * @return bool
   */
  protected function gitResetRepository($clone_path, $sha) {
    if (!$this->doExec("cd $clone_path && git reset --hard $sha", FALSE)) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * @param $clone_path
   * @param $tag
   * @return bool
   */
  protected function resetGitRepositoryToTag($clone_path, $tag) {
    $output = [];

    if (!$this->doExec("cd $clone_path && git rev-list -n 1 $tag", TRUE, $output)) {
      return FALSE;
    }

    $sha = $output[0];

    if (!($this->gitResetRepository($clone_path, $sha) &&
      $this->doExec("cd $clone_path && git push -f", FALSE))
    ) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * @param $cms
   * @param $source_site
   * @return bool
   * @throws \Terminus\Exceptions\TerminusException
   */
  protected function getCmsVersion($cms, $source_site) {
    if ($cms == "drupal") {
      // drush command
      $result = $this->doFrameworkCommand($source_site, "dev", "drush st --format=json");
      if ($result['exit_code'] != 0) {
        $this->log()
          ->error("Failed to determine site's {cms} version.", ["cms" => $cms]);
        return FALSE;
      }
      else {
        $data = json_decode($result['output']);
        // deal with a hyphenated property name.
        return $data->{'drupal-version'};
      }
    }
    else {
      throw new TerminusException("getCmsVersion not implemented for {cms}.", ['cms' => $cms]);
    }

  }

  /**
   * @param $cmd
   * @param bool $verbose
   * @param array $output
   * @return bool
   */
  protected function doExec($cmd, $verbose = FALSE, &$output = []) {
    $exit = '';

    if ($verbose) {
      $this->log()->info("", ["Exec" => $cmd]);
    }

    exec($cmd, $output, $exit);

    if ($exit != 0) {
      if ($verbose) {
        $this->log()
          ->error("", ["Output" => implode("\n", $output)]);
      }
      return FALSE;
    }

    if (($verbose) && (count($output))) {
      $this->log()
        ->info("", ["Output" => implode("\n", $output)]);
    }

    return TRUE;
  }

  /**
   * @param \Terminus\Models\Site $site
   * @param $environment
   * @param $command
   * @return mixed
   * @throws \Terminus\Exceptions\TerminusException
   */
  protected function doFrameworkCommand(\Terminus\Models\Site $site, $environment, $command) {
    $environment = $site->environments->get($environment);
    $result = $environment->sendCommandViaSsh($command);

    return $result;
  }

  /**
   * If the site has > 1 hostname for the dev environment, return the first domain that doesn't match pantheonsite.io. This hopefully is the organization's custom dev hostname on Pantheon.
   *
   * @param \Terminus\Models\Site $site
   * @return mixed|string
   */
  protected function getPantheonDevHostname(\Terminus\Models\Site $site) {
    $site_name = $site->get('name');
    $env = $site->environments->get('dev');
    $data = array_map(
      function ($hostname) {
        $info = $hostname->serialize();
        return $info;
      },
      $env->hostnames->all()
    );
    $hostnames = array_column($data, 'domain');

    if (count($hostnames) > 1) {
      $hostnames = array_filter($hostnames, function ($hostname) {
        if (strpos($hostname, 'pantheonsite.io') === FALSE) {
          return TRUE;
        }
        return FALSE;
      });
      $hostname = array_shift($hostnames);
      $domain = str_replace('dev-' . $site_name . '.', '', $hostname);

      return $domain;
    }
    else {
      return "pantheonsite.io";
    }

  }

  /**
   * @param \Terminus\Models\Site $site
   * @param array $filter_envs
   * @return array
   */
  protected function getSiteUrls(\Terminus\Models\Site $site, $filter_envs = []) {
    $site_env_info = $this->getEnvironmentsInfo($site);
    $site_environments = array_column($site_env_info, 'initialized', 'id');
    $site_name = $site->get('name');
    $pantheon_dev_domain = $this->getPantheonDevHostname($site);

    $env_urls = [];
    $env_urls['dashboard'] = sprintf(
      '%s://%s/sites/%s%s',
      Config::get('dashboard_protocol'),
      Config::get('dashboard_host'),
      $site->id,
      "#dev"
    );

    // Enforce the order of the environments.
    foreach ($this->default_envs as $env) {
      if ($site_environments[$env] != "true") {
        continue;
      }
      $env_urls[$env] = "http://$env-$site_name.$pantheon_dev_domain";
    }

    return $env_urls;
  }
}
