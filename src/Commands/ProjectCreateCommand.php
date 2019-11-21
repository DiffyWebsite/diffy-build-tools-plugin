<?php

namespace Diffy\TerminusBuildTools\Commands;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\DataStore\FileStore;
use Pantheon\TerminusBuildTools\ServiceProviders\ProviderEnvironment;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Set up DIFFY environment variables with CircleCI.
 */
class ProjectCreateCommand extends TerminusCommand {

  /**
   * Diffy API key.
   *
   * @var string
   */
  protected $key;

  /**
   * Diffy API token.
   */
  protected $token;

  /**
   * Diffy project id.
   */
  protected $project_id;

  /**
   * Populate DIFFY related environment variables to CircleCI.
   *
   * @command diffy:project:create
   */
  public function createProject() {
    $directory = $this->getConfig()->get('cache_dir') . '/build-tools';
    $github = FALSE;
    $circle = FALSE;
    $sitename = '';
    foreach (scandir($directory) as $filename) {
      if (in_array($filename, ['.', '..'])) {
        continue;
      }
      if (strpos($filename, 'GITHUB_TOKEN')) {
        $github = json_decode(file_get_contents($directory . '/' . $filename));
      }
      if (strpos($filename, 'CIRCLE_TOKEN')) {
        $circle = json_decode(file_get_contents($directory . '/' . $filename));
      }
      if (strpos($filename, 'SITE_NAME')) {
        $sitename = json_decode(file_get_contents($directory . '/' . $filename));
      }
    }

    $io = new SymfonyStyle($this->input(), $this->output());
    if (!$github || !$circle || empty($sitename)) {
      $io->error("Sorry, looks like you are not using Github, CircleCI or your cached configuration does not have site name. We can not set your DIFFY credentials automatically.");
      return;
    }

    // Ask user to provide key and project id.
    $this->enterApiKey();
    $this->enterProjectId();

    $client = new Client();

    // Save provided key and project id to CircleCI.
    $headers = [
      'Content-Type' => 'application/json',
      'User-Agent' => ProviderEnvironment::USER_AGENT,
      'Accept' => 'application/json',
    ];

    $url = 'https://api.github.com/user';
    $headers['Authorization'] = 'token ' . $github;
    $res = $client->request('GET', $url, [
      'headers' => $headers,
    ]);
    $github_username = json_decode($res->getBody()->getContents(), TRUE)['login'];

    $url = "https://circleci.com/api/v1.1/project/gh/$github_username/$sitename/envvar";

    $variables = [
      'DIFFY_API_KEY' => $this->key,
      'DIFFY_PROJECT_ID' => $this->project_id,
    ];

    foreach ($variables as $key => $value) {
      $data = ['name' => $key, 'value' => $value];
      $res = $client->request('POST', $url, [
        'headers' => $headers,
        'auth' => [$circle, ''],
        'json' => $data,
      ]);
    }

    $io->success('Variables set for site ' . $sitename);
  }

  /**
   * Prompts user to enter API Key.
   */
  protected function enterApiKey() {
    $io = new SymfonyStyle($this->input(), $this->output());
    while (true) {
      $io->write("\n\n");
      $prompt = "Please generate a Diffy personal API key by visiting the page:\n\n    https://app.diffy.website/#/keys\n\n For more information, see:\n\n    https://diffy.website/documentation/getting-started-apis.";
      $api_key = $io->askHidden($prompt);
      $api_key = trim($api_key);

      // If the credential validates, set it and return. Otherwise
      // we'll ask again.
      if ($this->validateKey($api_key)) {
        print_r($this->getConfig()->get('cache_dir') . '/build-tools');
        $credential_store = new FileStore($this->getConfig()->get('cache_dir') . '/build-tools');
        $cache_key = $this->session()->getUser()->id . '-diffy-key';
        $credential_store->set($cache_key, $api_key);

        $this->key = $api_key;
        break;
      }
      else {
        $io->error("Provided API Key is invalid");
      }
    }
  }

  /**
   * Validates key and saves token.
   */
  protected function validateKey($api_key) {
    $client = new Client();
    try {
      $response = $client->request('POST', 'https://app.diffy.website/api/auth/key', [
        'json' => [
          'key' => $api_key,
        ]
      ]);
    }
    catch (ClientException $e) {
      // If response was 401.
      return FALSE;
    }
    $body = json_decode($response->getBody()->getContents(), TRUE);
    if (isset($body['token'])) {
      $this->token = $body['token'];
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Ask user to enter Project Id.
   */
  protected function enterProjectId() {
    $io = new SymfonyStyle($this->input(), $this->output());
    $page = 0;

    while (true) {
      $io->write("\n\n");
      $projects = $this->getProjects($page);

      $io->write("Available projects (page $page):\n");
      foreach ($projects as $project) {
        $io->write('[' . $project['id'] . '] ' . $project['name'] . "\n");
      }

      $prompt = "Enter id of the project (N - Next page. P - Previous page)";
      $project_id = $io->ask($prompt);

      $io->write("Entered: " . $project_id . "\n");

      if (!is_numeric($project_id)) {
        $command = strtoupper($project_id);
        if (!in_array($command, ['N', 'P'])) {
          $io->error("Invalid command entered");
        }
        if ($command == 'N') {
          $page++;
        }
        if ($command == 'P' && $page > 0) {
          $page--;
        }
      }
      else {
        if ($this->isProjectValid($project_id)) {
          $credential_store = new FileStore($this->getConfig()->get('cache_dir') . '/build-tools');
          $cache_key = $this->session()->getUser()->id . '-diffy-project';
          $credential_store->set($cache_key, $project_id);

          $this->project_id = $project_id;
          return;
        }
        $io->error("Entered project either does not exist or you do not have access to it.");
      }
    }
  }

  /**
   * Get the list of projects.
   */
  protected function getProjects($page) {
    $client = new Client();
    try {
      $response = $client->request('GET', 'https://app.diffy.website/api/projects', [
        'query' => [
          'page' => $page,
        ],
        'headers' => [
          'Authorization' => 'Bearer ' . $this->token,
          'Accept' => 'application/json',
        ],
      ]);

      $body = json_decode($response->getBody()->getContents(), TRUE);
      return $body['projects'];
    }
    catch (\Exception $e) {

    }
    return [];
  }

  /**
   * Check if current user has access to this project.
   */
  protected function isProjectValid($project_id) {
    $client = new Client();
    try {
      $response = $client->request('GET', 'https://app.diffy.website/api/projects/' . $project_id, [
        'headers' => [
          'Authorization' => 'Bearer ' . $this->token,
          'Accept' => 'application/json',
        ],
      ]);

      $body = json_decode($response->getBody()->getContents(), TRUE);
      return isset($body['name']);
    }
    catch (\Exception $e) {

    }
    return FALSE;
  }

}
