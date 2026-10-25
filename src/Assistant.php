<?php

declare(strict_types=1);

namespace PlainShow\Install;

use RuntimeException;

/**
 * The Assistant's local language model: which build of llama.cpp suits this
 * machine, which model fits its memory, and the systemd units that run it.
 *
 * The model starts when core first asks (socket activation on 127.0.0.1:18090),
 * stops after 30 quiet minutes, runs at the lowest CPU and disk priority so the
 * sites always go first, and keeps the instructions it has read on disk so a
 * fresh start does not read them again. Core finds it through the platform
 * file (`system.assistant` = `on`).
 */
final class Assistant
{
    public const DIR = '/opt/plainshow-ai';

    public const USER = 'plainshow-ai';

    public const STATE = '/var/lib/plainshow-ai';

    /** The llama.cpp commit PlainShow is tested with (the flags below exist in it). */
    public const LLAMA_REPO = 'https://github.com/ggml-org/llama.cpp.git';

    public const LLAMA_COMMIT = '63412d8737b3efa30748dd79ceb2c93487e19f2b';

    /** Models by the memory they need: the larger writes far better Swedish and Dutch. */
    public const MODELS = [
        '1.7b' => [
            'file' => 'Qwen3-1.7B-Q4_K_M.gguf',
            'url' => 'https://huggingface.co/unsloth/Qwen3-1.7B-GGUF/resolve/main/Qwen3-1.7B-Q4_K_M.gguf',
            'sha256' => 'b139949c5bd74937ad8ed8c8cf3d9ffb1e99c866c823204dc42c0d91fa181897',
            'memory' => '4G',
            'needs_gb' => 6,
        ],
        '0.6b' => [
            'file' => 'Qwen3-0.6B-Q4_K_M.gguf',
            'url' => 'https://huggingface.co/unsloth/Qwen3-0.6B-GGUF/resolve/main/Qwen3-0.6B-Q4_K_M.gguf',
            'sha256' => 'ac2d97712095a558e31573f62f466a3f9d93990898b0ec79d7c974c1780d524a',
            'memory' => '2G',
            'needs_gb' => 3,
        ],
    ];

    /** Memory in whole gigabytes (MemTotal). */
    public static function memoryGb(): int
    {
        preg_match('/^MemTotal:\s+(\d+)/m', (string) @file_get_contents('/proc/meminfo'), $found);

        return (int) round(((int) ($found[1] ?? 0)) / 1024 / 1024);
    }

    /** The model for this machine's memory, or null when it has too little for any. */
    public static function model(): ?string
    {
        foreach (self::MODELS as $id => $model) {
            if (self::memoryGb() >= $model['needs_gb']) {
                return $id;
            }
        }

        return null;
    }

    /**
     * The build for this hardware: 'cuda' for an NVIDIA card with the CUDA
     * compiler, 'vulkan' for another graphics card on a PC (AMD, Intel, or
     * NVIDIA without CUDA), else 'cpu', compiled for this very processor
     * (AVX2 or AVX-512 on x86, dot-product and matrix instructions on ARM).
     * Single-board computers (a Raspberry Pi) use the CPU: their small GPUs
     * are slower than their cores for this.
     */
    public static function backend(): string
    {
        $arm = in_array(php_uname('m'), ['aarch64', 'arm64', 'armv7l'], true);
        $nvidia = is_dir('/proc/driver/nvidia') || Shell::exists('nvidia-smi');
        if ($nvidia && (Shell::exists('nvcc') || is_file('/usr/local/cuda/bin/nvcc'))) {
            return 'cuda';
        }
        if (! $arm && ($nvidia || self::pciGpu())) {
            return 'vulkan';
        }

        return 'cpu';
    }

    /** A graphics card from AMD, Intel or NVIDIA on the PCI bus (vendor ids 1002, 8086, 10de). */
    private static function pciGpu(): bool
    {
        foreach (glob('/sys/bus/pci/devices/*') ?: [] as $device) {
            $class = trim((string) @file_get_contents("$device/class"));
            $vendor = trim((string) @file_get_contents("$device/vendor"));
            // 0x03xxxx: display controllers.
            if (str_starts_with($class, '0x03') && in_array($vendor, ['0x1002', '0x8086', '0x10de'], true)) {
                return true;
            }
        }

        return false;
    }

    /** Compiler, CMake and, for Vulkan, its headers and shader compiler. */
    public static function packages(Distro $distro, string $backend): array
    {
        $build = match ($distro->family) {
            'debian' => ['build-essential', 'cmake', 'git'],
            'fedora' => ['gcc', 'gcc-c++', 'make', 'cmake', 'git'],
            'arch' => ['base-devel', 'cmake', 'git'],
            'suse' => ['gcc', 'gcc-c++', 'make', 'cmake', 'git'],
        };
        if ($backend !== 'vulkan') {
            return $build;
        }

        return [...$build, ...match ($distro->family) {
            'debian' => ['libvulkan-dev', 'glslc', 'mesa-vulkan-drivers'],
            'fedora' => ['vulkan-headers', 'vulkan-loader-devel', 'glslc', 'mesa-vulkan-drivers'],
            'arch' => ['vulkan-headers', 'vulkan-icd-loader', 'shaderc'],
            'suse' => ['vulkan-headers', 'vulkan-devel', 'shaderc'],
        }];
    }

    /** CMake options for the build: always native to this CPU, plus the GPU when there is one. */
    public static function cmakeOptions(string $backend): array
    {
        $options = ['-DCMAKE_BUILD_TYPE=Release', '-DGGML_NATIVE=ON', '-DLLAMA_CURL=OFF', '-DLLAMA_BUILD_TESTS=OFF', '-DGGML_CCACHE=OFF'];

        return [...$options, ...match ($backend) {
            'cuda' => ['-DGGML_CUDA=ON'],
            'vulkan' => ['-DGGML_VULKAN=ON'],
            default => [],
        }];
    }

    /** Threads for the model: the physical cores, so it never fights itself for one. */
    public static function threads(): int
    {
        $cores = [];
        foreach (explode("\n\n", (string) @file_get_contents('/proc/cpuinfo')) as $block) {
            if (preg_match('/^physical id\s*:\s*(\d+)/m', $block, $socket) && preg_match('/^core id\s*:\s*(\d+)/m', $block, $core)) {
                $cores["$socket[1]-$core[1]"] = true;
            }
        }
        $count = $cores ? count($cores) : (int) trim(Shell::run(['nproc']));

        return max(1, min(16, $count));
    }

    /** systemd's socket proxy: /lib on Debian, /usr/lib elsewhere. */
    public static function proxyd(): string
    {
        foreach (['/usr/lib/systemd/systemd-socket-proxyd', '/lib/systemd/systemd-socket-proxyd'] as $path) {
            if (is_file($path)) {
                return $path;
            }
        }
        throw new RuntimeException('systemd-socket-proxyd is missing; the Assistant needs it to start and stop its model.');
    }

    /** The three units: the socket core talks to, the proxy that wakes the model, the model itself. */
    public static function units(string $model, string $backend, int $threads): array
    {
        $info = self::MODELS[$model];
        $dir = self::DIR;
        $state = self::STATE;
        $gpu = $backend === 'cpu' ? '' : ' -ngl 99';
        // The graphics card, through whichever of its groups this system has.
        $groups = array_values(array_filter(['video', 'render'], fn ($group) => posix_getgrnam($group) !== false));
        $devices = $backend === 'cpu' || ! $groups ? '' : 'SupplementaryGroups='.implode(' ', $groups)."\n";
        $proxyd = self::proxyd();
        $header = Templates::header('the Assistant\'s language model');

        return [
            'plainshow-ai-proxy.socket' => <<<UNIT
{$header}[Unit]
Description=PlainShow Assistant model socket

[Socket]
ListenStream=127.0.0.1:18090

[Install]
WantedBy=sockets.target

UNIT,
            'plainshow-ai-proxy.service' => <<<UNIT
{$header}# Passes connections to the model's server and exits after 30 quiet minutes,
# which stops the model too (StopWhenUnneeded) and frees its memory.
[Unit]
Description=PlainShow Assistant model proxy
Requires=plainshow-ai.service
After=plainshow-ai.service

[Service]
ExecStart={$proxyd} --exit-idle-time=30min 127.0.0.1:18091
PrivateTmp=yes

UNIT,
            'plainshow-ai.service' => <<<UNIT
{$header}# Started by plainshow-ai-proxy when a request arrives; stopped with it when idle.
[Unit]
Description=PlainShow Assistant model (llama.cpp, {$backend})
StopWhenUnneeded=yes

[Service]
User=plainshow-ai
Group=plainshow-ai
Environment=LD_LIBRARY_PATH={$dir}/bin
# Only the newest saved instructions are kept (about 200 MB each).
ExecStartPre=/bin/sh -c "ls -t {$state}/instructions-*.bin 2>/dev/null | tail -n +2 | xargs -r rm -f"
ExecStart={$dir}/bin/llama-server -m {$dir}/models/{$info['file']} --host 127.0.0.1 --port 18091 -c 4096 -t {$threads} -np 1 --jinja --no-webui --cache-reuse 256 --slot-save-path {$state}{$gpu}
# The unit counts as started once the model answers, so the first request waits for it.
ExecStartPost=/bin/sh -c 'for i in \$(seq 1 480); do curl -sf -o /dev/null http://127.0.0.1:18091/health && exit 0; sleep 0.25; done; exit 1'
# Lowest claim on the CPU and disk: pages and other services go first; alone, it still uses every core.
Nice=15
CPUWeight=10
IOWeight=10
MemoryMax={$info['memory']}
{$devices}NoNewPrivileges=yes
ProtectSystem=strict
ProtectHome=yes
PrivateTmp=yes
StateDirectory=plainshow-ai

UNIT,
        ];
    }
}
