<?php

/**
 * Version Bump Script
 * Automatically increments version in composer.json and creates Git tag
 */
class VersionBumper
{
    private string $composerPath;

    private array $composerData;

    public function __construct()
    {
        $this->composerPath = __DIR__.'/../composer.json';
        $this->loadComposerData();
    }

    private function loadComposerData(): void
    {
        if (! file_exists($this->composerPath)) {
            throw new Exception('composer.json not found');
        }

        $content = file_get_contents($this->composerPath);
        $this->composerData = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON in composer.json: '.json_last_error_msg());
        }
    }

    private function saveComposerData(): void
    {
        $content = json_encode($this->composerData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($this->composerPath, $content."\n");
    }

    public function getCurrentVersion(): string
    {
        return $this->composerData['version'] ?? '0.0.0';
    }

    public function bumpVersion(string $type = 'patch'): string
    {
        $currentVersion = $this->getCurrentVersion();
        $versionParts = explode('.', $currentVersion);

        if (count($versionParts) !== 3) {
            throw new Exception('Invalid version format. Expected: x.y.z');
        }

        [$major, $minor, $patch] = array_map('intval', $versionParts);

        switch ($type) {
            case 'major':
                $major++;
                $minor = 0;
                $patch = 0;
                break;
            case 'minor':
                $minor++;
                $patch = 0;
                break;
            case 'patch':
            default:
                $patch++;
                break;
        }

        $newVersion = "{$major}.{$minor}.{$patch}";
        $this->composerData['version'] = $newVersion;
        $this->saveComposerData();

        return $newVersion;
    }

    public function createGitTag(string $version): bool
    {
        $tagName = "v{$version}";

        // Check if tag already exists
        $existingTags = shell_exec('git tag -l') ?: '';
        if (strpos($existingTags, $tagName) !== false) {
            echo "Tag {$tagName} already exists. Skipping tag creation.\n";

            return false;
        }

        // Create and push tag
        $commands = [
            'git add composer.json',
            "git commit -m \"Bump version to {$version}\"",
            "git tag {$tagName}",
            'git push origin main',
            "git push origin {$tagName}",
        ];

        foreach ($commands as $command) {
            echo "Executing: {$command}\n";
            $output = shell_exec($command.' 2>&1');
            if ($output) {
                echo $output;
            }
        }

        return true;
    }

    public function run(string $type = 'patch'): void
    {
        echo "🚀 Starting version bump process...\n";

        $newVersion = $this->bumpVersion($type);
        echo "✅ Version bumped to: {$newVersion}\n";

        if ($this->createGitTag($newVersion)) {
            echo "✅ Git tag v{$newVersion} created and pushed\n";
        }

        echo "🎉 Version bump completed successfully!\n";
    }
}

// CLI Usage
if (php_sapi_name() === 'cli') {
    $type = $argv[1] ?? 'patch';

    if (! in_array($type, ['major', 'minor', 'patch'])) {
        echo "❌ Invalid version type. Use: major, minor, or patch\n";
        exit(1);
    }

    try {
        $bumper = new VersionBumper;
        $bumper->run($type);
    } catch (Exception $e) {
        echo '❌ Error: '.$e->getMessage()."\n";
        exit(1);
    }
}
