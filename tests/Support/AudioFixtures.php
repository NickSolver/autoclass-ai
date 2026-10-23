<?php

namespace Tests\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Синтетические записи для тестов, генерируются ffmpeg — бинарные фикстуры не хранятся в репозитории
 * и не содержат реальной речи (ТЗ, разд. 17 п. 10).
 */
trait AudioFixtures
{
    /** @var list<string> */
    private array $fixtureDirs = [];

    protected function audioFile(string $format = 'mp3', float $seconds = 3.0, int $frequency = 440, ?string $name = null): UploadedFile
    {
        $dir = $this->fixtureDir();
        $path = "{$dir}/tone-{$frequency}-{$seconds}.{$format}";
        $codec = match ($format) {
            'mp3' => ['-c:a', 'libmp3lame', '-b:a', '64k'],
            'wav' => ['-c:a', 'pcm_s16le'],
            'm4a' => ['-c:a', 'aac', '-b:a', '64k'],
        };
        Process::run(['ffmpeg', '-nostdin', '-v', 'error', '-f', 'lavfi', '-i', "sine=frequency={$frequency}:duration={$seconds}", '-ac', '2', '-ar', '44100', ...$codec, '-y', $path])->throw();

        return new UploadedFile($path, $name ?? "звонок-{$frequency}.{$format}", null, null, true);
    }

    protected function videoFile(): UploadedFile
    {
        $path = $this->fixtureDir().'/video.m4a';
        Process::run(['ffmpeg', '-nostdin', '-v', 'error', '-f', 'lavfi', '-i', 'testsrc=duration=2:size=64x64:rate=5',
            '-f', 'lavfi', '-i', 'sine=duration=2', '-c:v', 'mpeg4', '-c:a', 'aac', '-f', 'mp4', '-y', $path])->throw();

        return new UploadedFile($path, 'video.m4a', null, null, true);
    }

    protected function rawFile(string $contents, string $name): UploadedFile
    {
        $path = $this->fixtureDir().'/'.Str::random(8).'-'.$name;
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function fixtureDir(): string
    {
        $dir = sys_get_temp_dir().'/avtoklass-audio-'.Str::random(8);
        @mkdir($dir, 0o700, true);
        $this->fixtureDirs[] = $dir;

        return $dir;
    }

    protected function tearDownAudioFixtures(): void
    {
        foreach ($this->fixtureDirs as $dir) {
            array_map('unlink', glob("{$dir}/*") ?: []);
            @rmdir($dir);
        }
        $this->fixtureDirs = [];
    }
}
