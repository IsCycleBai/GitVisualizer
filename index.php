<?php
/**
 * Git Commits Visualizer
 * 通过 GitHub/GitLab 公开 API 获取提交信息并生成 SVG 可视化
 */

define('GITVIZ_VERSION', '1.0.0');
define('GITVIZ_LOG_PATH', dirname(__FILE__) . '/logs');
define('GITVIZ_LOG_FILE', GITVIZ_LOG_PATH . '/gitviz-' . date('Y-m-d') . '.log');
define('GITVIZ_DEBUG', false);

define('GITVIZ_CACHE_DURATION', 300); // 缓存持续时间（秒）
define('GITVIZ_RATE_LIMIT_DURATION', 30); // 频率限制时间窗口（秒）
define('GITVIZ_RATE_LIMIT_MAX_REQUESTS', 5); // 频率限制最大请求数
define('GITVIZ_CACHE_PATH', dirname(__FILE__) . '/cache');

class Logger {
    private $logFile;
    
    public function __construct($logFile = null) {
        $this->logFile = $logFile ?? GITVIZ_LOG_FILE;
        if (!is_dir(dirname($this->logFile))) {
            mkdir(dirname($this->logFile), 0755, true);
        }
    }
    
    public function log($message, $level = 'INFO') {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = sprintf("[%s] [%s] %s\n", $timestamp, $level, $message);
        if (GITVIZ_DEBUG) {
            error_log($logEntry);
        }
        file_put_contents($this->logFile, $logEntry, FILE_APPEND);
    }
}

class CacheManager {
    private $cacheDir;
    
    public function __construct() {
        $this->cacheDir = GITVIZ_CACHE_PATH;
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }
    
    private function getCacheKey($repoUrl, $branch, $limit) {
        return md5($repoUrl . $branch . $limit);
    }
    
    private function getCacheFile($key) {
        return $this->cacheDir . '/' . $key . '.cache';
    }
    
    public function get($repoUrl, $branch, $limit) {
        $key = $this->getCacheKey($repoUrl, $branch, $limit);
        $file = $this->getCacheFile($key);
        
        if (file_exists($file) && (time() - filemtime($file)) < GITVIZ_CACHE_DURATION) {
            return unserialize(file_get_contents($file));
        }
        
        return null;
    }
    
    public function set($repoUrl, $branch, $limit, $data) {
        $key = $this->getCacheKey($repoUrl, $branch, $limit);
        $file = $this->getCacheFile($key);
        
        file_put_contents($file, serialize($data));
    }

    public function getRemainingTime($repoUrl, $branch, $limit) {
        $key = $this->getCacheKey($repoUrl, $branch, $limit);
        $file = $this->getCacheFile($key);
        
        if (file_exists($file)) {
            $remainingTime = GITVIZ_CACHE_DURATION - (time() - filemtime($file));
            return max(0, $remainingTime);
        }
        
        return 0;
    }
}

class RateLimiter {
    private $cacheDir;
    
    public function __construct() {
        $this->cacheDir = GITVIZ_CACHE_PATH . '/ratelimit';
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }
    
    private function getClientIP() {
        return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    
    private function getRateLimitFile() {
        return $this->cacheDir . '/' . md5($this->getClientIP()) . '.limit';
    }
    
    public function isLimited() {
        $file = $this->getRateLimitFile();
        
        if (!file_exists($file)) {
            $data = ['requests' => [], 'count' => 0];
        } else {
            $data = unserialize(file_get_contents($file));
            // 清理过期的请求记录
            $data['requests'] = array_filter($data['requests'], function($time) {
                return (time() - $time) < GITVIZ_RATE_LIMIT_DURATION;
            });
            $data['count'] = count($data['requests']);
        }
        
        if ($data['count'] >= GITVIZ_RATE_LIMIT_MAX_REQUESTS) {
            return true;
        }
        
        // 记录新的请求
        $data['requests'][] = time();
        $data['count']++;
        file_put_contents($file, serialize($data));
        
        return false;
    }
}

class GitVisualizer {
    private $config;
    private $errors = [];
    private $logger;

    private $cache;
    private $rateLimiter;
    private $remainingCacheTime = null;
    
    private $typeEmojis = [
        'feat' => '✨',
        'fix' => '🔧',
        'docs' => '📝',
        'style' => '💄',
        'refactor' => '♻️',
        'perf' => '⚡️',
        'test' => '🧪',
        'chore' => '🔨',
        'build' => '📦',
        'ci' => '🎯',
        'revert' => '⏪',
        'other' => '💡'
    ];

    public function __construct() {
        $this->logger = new Logger();
        $this->cache = new CacheManager();
        $this->rateLimiter = new RateLimiter();
        $this->config = [
            'repo_url' => $_GET['repo'] ?? null,
            'limit' => isset($_GET['limit']) ? min(max((int)$_GET['limit'], 1), 50) : 10,
            'dark_mode' => $this->detectDarkMode(),
            'branch' => $_GET['branch'] ?? 'main'
        ];
        
        $this->logger->log("Started GitVisualizer v" . GITVIZ_VERSION);
        $this->logger->log("Configuration: " . json_encode($this->config));
    }


    private function detectDarkMode() {
        return isset($_GET['dark_mode']) ? 
            filter_var($_GET['dark_mode'], FILTER_VALIDATE_BOOLEAN) : 
            (isset($_SERVER['HTTP_SEC_CH_PREFERS_COLOR_SCHEME']) && 
             $_SERVER['HTTP_SEC_CH_PREFERS_COLOR_SCHEME'] === 'dark');
    }

    public function process() {
        try {
            // 检查访问频率限制
            if ($this->rateLimiter->isLimited()) {
                $this->errors[] = 'Rate limit exceeded. Please try again later.';
                $this->logger->log("Rate limit exceeded for IP", 'WARNING');
                $this->sendError(429);
                return;
            }

            $this->validateInput();
            if (!empty($this->errors)) {
                $this->logger->log("Validation errors: " . json_encode($this->errors), 'ERROR');
                $this->sendError(400);
                return;
            }

            // 检查缓存
            $cachedCommits = $this->cache->get(
                $this->config['repo_url'],
                $this->config['branch'],
                $this->config['limit']
            );

            if ($cachedCommits !== null) {
                $this->remainingCacheTime = $this->cache->getRemainingTime(
                    $this->config['repo_url'],
                    $this->config['branch'],
                    $this->config['limit']
                );
                $this->logger->log("Using cached data for " . $this->config['repo_url']);
                $svg = $this->generateSVG($cachedCommits);
            } else {
                $commits = $this->fetchCommits();
                $this->cache->set(
                    $this->config['repo_url'],
                    $this->config['branch'],
                    $this->config['limit'],
                    $commits
                );
                $svg = $this->generateSVG($commits);
            }
            
            header('Content-Type: image/svg+xml');
            header('Cache-Control: public, max-age=' . GITVIZ_CACHE_DURATION);
            echo $svg;
            
            $this->logger->log("Successfully generated SVG for " . $this->config['repo_url']);

        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
            $this->logger->log("Error: " . $e->getMessage(), 'ERROR');
            $this->sendError(500);
        }
    }

    private function validateInput() {
        if (empty($this->config['repo_url'])) {
            $this->errors[] = 'Repository URL is required';
            return;
        }

        if (!filter_var($this->config['repo_url'], FILTER_VALIDATE_URL)) {
            $this->errors[] = 'Invalid repository URL';
            return;
        }

        // 解析 URL 来确定是 GitHub 还是 GitLab
        $parsedUrl = parse_url($this->config['repo_url']);
        $pathParts = explode('/', trim($parsedUrl['path'], '/'));
        
        if (strpos($parsedUrl['host'], 'github.com') !== false) {
            if (count($pathParts) < 2) {
                $this->errors[] = 'Invalid GitHub repository URL format';
                return;
            }
            $this->config['platform'] = 'github';
            $this->config['owner'] = $pathParts[0];
            $this->config['repo'] = $pathParts[1];
        } elseif (strpos($parsedUrl['host'], 'gitlab.com') !== false) {
            $this->config['platform'] = 'gitlab';
            $this->config['project_path'] = implode('/', $pathParts);
        } else {
            $this->errors[] = 'Only GitHub and GitLab URLs are supported';
            return;
        }
    }

    private function fetchCommits() {
        $this->logger->log("Fetching commits...");
        
        if ($this->config['platform'] === 'github') {
            return $this->fetchGitHubCommits();
        } else {
            return $this->fetchGitLabCommits();
        }
    }

    private function fetchGitHubCommits() {
        $apiUrl = sprintf(
            'https://api.github.com/repos/%s/%s/commits?sha=%s&per_page=%d',
            $this->config['owner'],
            $this->config['repo'],
            $this->config['branch'],
            $this->config['limit']
        );

        $opts = [
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: PHP GitViz',
                    'Accept: application/vnd.github.v3+json'
                ]
            ]
        ];

        $context = stream_context_create($opts);
        $response = file_get_contents($apiUrl, false, $context);
        
        if ($response === false) {
            throw new Exception('Failed to fetch commits from GitHub API');
        }

        $commits = json_decode($response, true);
        return array_map(function($commit) {
            $subject = $commit['commit']['message'];
            $messageParts = explode("\n", $subject, 2);
            
            // Parse conventional commits
            $type = 'other';
            $scope = '';
            $description = $messageParts[0];
            
            if (preg_match('/^(feat|fix|docs|style|refactor|perf|test|chore|build|ci|revert)(\(([^)]+)\))?:\s*(.*)/', $messageParts[0], $matches)) {
                $type = $matches[1];
                $scope = $matches[3] ?? '';
                $description = $matches[4];
            }

            return [
                'hash' => $commit['sha'],
                'author' => $commit['commit']['author']['name'],
                'date' => [
                    'timestamp' => strtotime($commit['commit']['author']['date']),
                    'formatted' => date('Y-m-d H:i:s', strtotime($commit['commit']['author']['date']))
                ],
                'type' => $type,
                'scope' => $scope,
                'title' => $description,
                'body' => isset($messageParts[1]) ? trim($messageParts[1]) : '',
                'emoji' => $this->typeEmojis[$type] ?? $this->typeEmojis['other']
            ];
        }, $commits);
    }

    private function fetchGitLabCommits() {
        $apiUrl = sprintf(
            'https://gitlab.com/api/v4/projects/%s/repository/commits?ref_name=%s&per_page=%d',
            urlencode($this->config['project_path']),
            $this->config['branch'],
            $this->config['limit']
        );

        $opts = [
            'http' => [
                'method' => 'GET',
                'header' => 'Accept: application/json'
            ]
        ];

        $context = stream_context_create($opts);
        $response = file_get_contents($apiUrl, false, $context);
        
        if ($response === false) {
            throw new Exception('Failed to fetch commits from GitLab API');
        }

        $commits = json_decode($response, true);
        return array_map(function($commit) {
            $messageParts = explode("\n", $commit['message'], 2);
            
            // Parse conventional commits
            $type = 'other';
            $scope = '';
            $description = $messageParts[0];
            
            if (preg_match('/^(feat|fix|docs|style|refactor|perf|test|chore|build|ci|revert)(\(([^)]+)\))?:\s*(.*)/', $messageParts[0], $matches)) {
                $type = $matches[1];
                $scope = $matches[3] ?? '';
                $description = $matches[4];
            }

            return [
                'hash' => $commit['id'],
                'author' => $commit['author_name'],
                'date' => [
                    'timestamp' => strtotime($commit['created_at']),
                    'formatted' => date('Y-m-d H:i:s', strtotime($commit['created_at']))
                ],
                'type' => $type,
                'scope' => $scope,
                'title' => $description,
                'body' => isset($messageParts[1]) ? trim($messageParts[1]) : '',
                'emoji' => $this->typeEmojis[$type] ?? $this->typeEmojis['other']
            ];
        }, $commits);
    }

    private function generateSVG($commits) {
        $colors = $this->config['dark_mode'] ? [
            'background' => '#1a1a1a',
            'text' => '#ffffff',
            'border' => '#333333',
            'feat' => '#4CAF50',
            'fix' => '#F44336',
            'docs' => '#2196F3',
            'refactor' => '#FF9800',
            'perf' => '#9C27B0',
            'test' => '#FFEB3B',
            'chore' => '#795548',
            'other' => '#9E9E9E'
        ] : [
            'background' => '#ffffff',
            'text' => '#000000',
            'border' => '#e0e0e0',
            'feat' => '#81C784',
            'fix' => '#E57373',
            'docs' => '#64B5F6',
            'refactor' => '#FFB74D',
            'perf' => '#BA68C8',
            'test' => '#FFF176',
            'chore' => '#A1887F',
            'other' => '#BDBDBD'
        ];

        $height = count($commits) * 120 + 40;
        if ($this->remainingCacheTime !== null) {
            $height += 30; // 为缓存提示信息增加高度
        }
        
        $svg = <<<SVG
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 $height" width="800">
    <rect width="100%" height="100%" fill="{$colors['background']}"/>
    <style>
        .commit-title { font: bold 14px system-ui; }
        .commit-body { font: 12px system-ui; }
        .commit-meta { font: italic 10px system-ui; }
        .commit-info { font: 10px monospace; }
        .commit-emoji { font: 14px system-ui; }
        .cache-notice { font: italic 12px system-ui; fill: {$colors['text']}; opacity: 0.8; }
    </style>

SVG;

        if ($this->remainingCacheTime !== null) {
            $svg .= <<<SVG
    <text x="20" y="20" class="cache-notice">
        此结果从缓存中取得, 距离缓存失效剩余 {$this->remainingCacheTime} 秒.
    </text>

SVG;
            $startY = 50; // 调整后续内容的起始位置
        } else {
            $startY = 20;
        }

        $y = $startY;
        foreach ($commits as $commit) {
            $color = $colors[$commit['type']] ?? $colors['other'];
            $title = htmlspecialchars($commit['title']);
            $body = htmlspecialchars($commit['body']);
            $meta = "{$commit['emoji']} {$commit['type']}" . ($commit['scope'] ? "({$commit['scope']})" : "");
            $author = htmlspecialchars($commit['author']);
            $date = $commit['date']['formatted'];
            $hash = substr($commit['hash'], 0, 7);

            $svg .= <<<SVG
    <g transform="translate(20,$y)">
        <rect width="760" height="100" rx="5" fill="$color" opacity="0.2" stroke="{$colors['border']}" stroke-width="1"/>
        <text x="10" y="20" fill="{$colors['text']}" class="commit-meta">$meta</text>
        <text x="10" y="40" fill="{$colors['text']}" class="commit-title">$title</text>
        <text x="10" y="60" fill="{$colors['text']}" class="commit-body">$body</text>
        <text x="10" y="85" fill="{$colors['text']}" class="commit-info">
            <tspan>$author</tspan>
            <tspan x="300">$date</tspan>
            <tspan x="500">$hash</tspan>
        </text>
    </g>

SVG;
            $y += 120;
        }

        $svg .= "</svg>";
        return $svg;
    }

    private function sendError($code) {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'errors' => $this->errors
        ]);
    }
}

// 错误处理
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// 处理请求
$visualizer = new GitVisualizer();
$visualizer->process();
