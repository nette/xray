<?php declare(strict_types=1);

namespace Nette\Xray;

use function fgets, fwrite, json_encode, stream_context_create, strlen, trim;


/**
 * Uploads the anonymized report to stats.nette.org with interactive opt-in.
 */
final class Uploader
{
	private const Endpoint = 'https://stats.nette.org/api/report';


	public function upload(Collector $collector, bool $autoUpload = false): void
	{
		if (!$autoUpload && !$this->askConsent()) {
			fwrite(STDOUT, "Upload skipped.\n\n");
			return;
		}

		$json = json_encode($collector, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';

		fwrite(STDOUT, "Uploading report...\n");

		$result = $this->sendRequest($json);
		if ($result === null) {
			fwrite(STDOUT, "Upload failed. You can manually send the JSON file later.\n\n");
			return;
		}

		fwrite(STDOUT, "Data sent. Thank you!\n\n");
	}


	private function askConsent(): bool
	{
		fwrite(STDOUT, "Would you like to share the anonymous usage data with the Nette team?\n");
		fwrite(STDOUT, "This helps improve the framework based on real-world usage patterns.\n");
		fwrite(STDOUT, "No file paths, variable names, or business logic is included.\n");
		fwrite(STDOUT, 'Send report to stats.nette.org? [y/N] ');

		$answer = trim(fgets(STDIN) ?: '');
		return $answer === 'y' || $answer === 'Y';
	}


	private function sendRequest(string $json): ?string
	{
		$context = stream_context_create([
			'http' => [
				'method' => 'POST',
				'header' => "Content-Type: application/json\r\nContent-Length: " . strlen($json) . "\r\n",
				'content' => $json,
				'timeout' => 15,
				'ignore_errors' => true,
			],
		]);

		$response = @file_get_contents(self::Endpoint, context: $context); // @ - network errors
		return $response !== false ? $response : null;
	}
}
