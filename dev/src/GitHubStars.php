<?php declare(strict_types=1);

namespace Nette\Xray;

use function is_array, is_string;
use const DIRECTORY_SEPARATOR;


/**
 * Stars GitHub repos of tracked packages used in the project.
 * Reads GitHub OAuth token from Composer's auth.json (no manual token needed).
 */
final class GitHubStars
{
	private const GithubApi = 'https://api.github.com';

	/** Composer packages where GitHub repo differs from packagist name */
	private const RepoMap = [
		'latte/latte' => 'nette/latte',
		'tracy/tracy' => 'nette/tracy',
		'dibi/dibi' => 'dg/dibi',
		'texy/texy' => 'dg/texy',
	];

	/** @nette/ npm package → GitHub repo mapping */
	private const NpmRepoMap = [
		'@nette/vite-plugin' => 'nette/vite-plugin',
		'@nette/eslint-plugin' => 'nette/eslint-plugin',
	];


	/**
	 * @param array<string, string> $composerPackages Composer package name => version
	 * @param array<string, string> $npmPackages npm package name => version
	 */
	public function run(array $composerPackages, array $npmPackages = []): void
	{
		if ($composerPackages === [] && $npmPackages === []) {
			return;
		}

		$repos = ['nette/nette' => true]; // always star the main repo
		foreach ($composerPackages as $package => $version) {
			$repos[self::RepoMap[$package] ?? $package] = true;
		}
		foreach ($npmPackages as $package => $version) {
			if (isset(self::NpmRepoMap[$package])) {
				$repos[self::NpmRepoMap[$package]] = true;
			}
		}

		$token = $this->getComposerToken();
		if ($token === null) {
			return;
		}

		fwrite(STDOUT, "Starring GitHub repos of Nette packages you use:\n");

		$starred = 0;
		foreach ($repos as $repo => $_) {
			if ($this->starRepo($repo, $token)) {
				fwrite(STDOUT, "  ★ {$repo}\n");
				$starred++;
			}
		}

		fwrite(STDOUT, $starred > 0
			? "Starred {$starred} repos. Thank you!\n\n"
			: "All repos already starred. Thank you!\n\n");
	}


	/**
	 * Reads GitHub OAuth token from COMPOSER_AUTH env or Composer's auth.json.
	 */
	private function getComposerToken(): ?string
	{
		// 1. COMPOSER_AUTH env (used in CI/CD)
		$composerAuth = getenv('COMPOSER_AUTH');
		if (is_string($composerAuth) && $composerAuth !== '') {
			$token = $this->extractToken(json_decode($composerAuth, associative: true));
			if ($token !== null) {
				return $token;
			}
		}

		// 2. Global auth.json
		foreach ($this->getComposerHomeCandidates() as $home) {
			$authFile = $home . '/auth.json';
			if (is_file($authFile)) {
				$token = $this->extractToken(json_decode(file_get_contents($authFile) ?: '', associative: true));
				if ($token !== null) {
					return $token;
				}
			}
		}

		return null;
	}


	/**
	 * Returns candidate paths for Composer home directory.
	 * @return string[]
	 */
	private function getComposerHomeCandidates(): array
	{
		$composerHome = getenv('COMPOSER_HOME');
		if (is_string($composerHome) && $composerHome !== '') {
			return [$composerHome];
		}

		if (DIRECTORY_SEPARATOR === '\\') {
			return [getenv('APPDATA') . '/Composer'];
		}

		$home = getenv('HOME');
		return [
			$home . '/.config/composer',  // XDG (Composer 2.x default)
			$home . '/.composer',          // legacy (Composer 1.x)
		];
	}


	private function extractToken(mixed $auth): ?string
	{
		if (!is_array($auth)) {
			return null;
		}

		$token = $auth['github-oauth']['github.com'] ?? null;
		return is_string($token) && $token !== '' ? $token : null;
	}


	private function starRepo(string $repo, string $token): bool
	{
		$url = self::GithubApi . '/user/starred/' . $repo;
		$context = stream_context_create([
			'http' => [
				'method' => 'PUT',
				'header' => "Authorization: Bearer {$token}\r\nUser-Agent: NetteXray/1.0\r\nContent-Length: 0\r\n",
				'content' => '',
				'timeout' => 10,
				'ignore_errors' => true,
			],
		]);

		$response = @file_get_contents($url, context: $context); // @ - network errors
		if ($response === false) {
			return false;
		}

		// 204 = starred, 304 = already starred
		$status = $http_response_header[0] ?? '';
		return str_contains($status, '204') || str_contains($status, '304');
	}
}
