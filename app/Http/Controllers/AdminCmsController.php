<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AdminCmsController extends Controller
{
    private const REPO_OWNER = 'evicktorovich';
    private const REPO_NAME = 'drawing-master';
    private const REPO_BRANCH = 'master';
    private const CONTENT_PATH = 'public/content.json';
    private const IMAGE_DIR = 'public/assets/img/uploaded';

    public function login(Request $request)
    {
        $password = (string) $request->input('password', '');
        $expected = (string) env('ADMIN_PASSWORD', '');
        if ($expected === '') {
            return response()->json(['error' => 'Admin password not configured on server'], 503);
        }
        if (!hash_equals($expected, $password)) {
            usleep(random_int(100000, 400000)); // throttle
            return response()->json(['error' => 'Invalid password'], 401);
        }
        $request->session()->put('cms_admin', true);
        $request->session()->regenerate();
        return response()->json(['ok' => true]);
    }

    public function logout(Request $request)
    {
        $request->session()->forget('cms_admin');
        return response()->json(['ok' => true]);
    }

    public function status(Request $request)
    {
        return response()->json(['authed' => $request->session()->get('cms_admin') === true]);
    }

    public function load(Request $request)
    {
        if (!$request->session()->get('cms_admin')) {
            return response()->json(['error' => 'Not authenticated'], 401);
        }
        $token = (string) env('GITHUB_TOKEN', '');
        if ($token === '') {
            return response()->json(['error' => 'GITHUB_TOKEN not configured'], 503);
        }
        try {
            $resp = Http::withToken($token)
                ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'])
                ->get($this->ghUrl('/contents/' . self::CONTENT_PATH), ['ref' => self::REPO_BRANCH]);
            if (!$resp->ok()) {
                return response()->json(['error' => 'GitHub: ' . $resp->json('message', 'unknown')], 502);
            }
            $data = $resp->json();
            $content = base64_decode(str_replace("\n", '', $data['content']));
            return response()->json([
                'content' => json_decode($content, true),
                'sha' => $data['sha'],
            ]);
        } catch (\Throwable $e) {
            Log::error('AdminCms load failed', ['err' => $e->getMessage()]);
            return response()->json(['error' => 'Load failed: ' . $e->getMessage()], 500);
        }
    }

    public function save(Request $request)
    {
        if (!$request->session()->get('cms_admin')) {
            return response()->json(['error' => 'Not authenticated'], 401);
        }
        $token = (string) env('GITHUB_TOKEN', '');
        if ($token === '') {
            return response()->json(['error' => 'GITHUB_TOKEN not configured'], 503);
        }
        $content = $request->input('content');
        $sha = (string) $request->input('sha', '');
        if (!is_array($content) || $sha === '') {
            return response()->json(['error' => 'content + sha required'], 400);
        }
        try {
            $content['updatedAt'] = now()->toIso8601String();
            $payload = json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            $resp = Http::withToken($token)
                ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'])
                ->put($this->ghUrl('/contents/' . self::CONTENT_PATH), [
                    'message' => 'Admin: content update (' . now()->format('Y-m-d H:i') . ')',
                    'content' => base64_encode($payload),
                    'sha' => $sha,
                    'branch' => self::REPO_BRANCH,
                ]);
            if (!$resp->ok()) {
                return response()->json(['error' => 'GitHub: ' . $resp->json('message', 'unknown')], 502);
            }
            return response()->json(['ok' => true, 'sha' => $resp->json('content.sha')]);
        } catch (\Throwable $e) {
            Log::error('AdminCms save failed', ['err' => $e->getMessage()]);
            return response()->json(['error' => 'Save failed: ' . $e->getMessage()], 500);
        }
    }

    public function upload(Request $request)
    {
        if (!$request->session()->get('cms_admin')) {
            return response()->json(['error' => 'Not authenticated'], 401);
        }
        $token = (string) env('GITHUB_TOKEN', '');
        if ($token === '') {
            return response()->json(['error' => 'GITHUB_TOKEN not configured'], 503);
        }
        $file = $request->file('image');
        if (!$file || !$file->isValid()) {
            return response()->json(['error' => 'No file uploaded'], 400);
        }
        if ($file->getSize() > 8 * 1024 * 1024) {
            return response()->json(['error' => 'Image too large (max 8 MB)'], 400);
        }
        $mime = $file->getMimeType();
        if (!Str::startsWith($mime, 'image/')) {
            return response()->json(['error' => 'Not an image file'], 400);
        }
        $safeName = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) . '.' . strtolower($file->getClientOriginalExtension() ?: 'jpg');
        $path = self::IMAGE_DIR . '/' . time() . '-' . $safeName;
        $b64 = base64_encode(file_get_contents($file->getRealPath()));
        try {
            $resp = Http::withToken($token)
                ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'])
                ->put($this->ghUrl('/contents/' . $path), [
                    'message' => 'Admin: upload ' . $safeName,
                    'content' => $b64,
                    'branch' => self::REPO_BRANCH,
                ]);
            if (!$resp->ok()) {
                return response()->json(['error' => 'GitHub: ' . $resp->json('message', 'unknown')], 502);
            }
            $url = '/' . substr($path, strlen('public/'));
            return response()->json(['ok' => true, 'url' => $url]);
        } catch (\Throwable $e) {
            Log::error('AdminCms upload failed', ['err' => $e->getMessage()]);
            return response()->json(['error' => 'Upload failed: ' . $e->getMessage()], 500);
        }
    }

    private function ghUrl(string $tail): string
    {
        return 'https://api.github.com/repos/' . self::REPO_OWNER . '/' . self::REPO_NAME . $tail;
    }
}
