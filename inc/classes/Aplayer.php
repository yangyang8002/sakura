<?php

namespace Sakura\API;

class Aplayer
{
    public $server;
    public $playlist_id;
    private $cookies;
    public $api_url;

    public function __construct() {
        $this->server = akina_option('aplayer_server');
        $this->playlist_id = akina_option('aplayer_playlistid');
        $this->cookies = akina_option('aplayer_cookie');
        $this->api_url = rest_url('sakura/v1/meting/aplayer');
        require('Meting.php');
    }

    public function get_data($type, $id) {
        $server = $this->server;
        $cookies = $this->cookies;
        $playlist_id = $this->playlist_id;
        $api = new \Sakura\API\Meting($server);
        if (!empty($cookies) && $server === "netease") $api->cookie($cookies);
        switch ($type) {
            case 'song':
                $data = $this->meting_with_fallback($api, 'song', $id);
                $data = $this->song_url($data);
                break;
            // case 'album':
            //     $data = $api->format(true)->album($id);
            //     $data=json_decode($data, true)["url"];
            //     break;
            case 'playlist':
                $data = $api->format(true)->playlist($playlist_id);
                $data = $this->format_playlist($data);
                break;
            case 'lyric':
                $data = $api->format(true)->lyric($id);
                $data = $this->format_lyric($data);
                break;
            case 'pic':
                $data = $this->meting_with_fallback($api, 'pic', $id);
                break;
            // case 'search':
            //     $data = $api->format(true)->search($id);
            //     $data=json_decode($data, true);
            //     break;
            default:
                $data = $this->meting_with_fallback($api, 'url', $id);
                $data = $this->song_url($data);
                break;
        }
        return $data;
    }

    /**
     * Call the Meting client and fall back to a public Meting API when the
     * direct request is blocked by platform risk control (e.g. NetEase -460
     * "网络环境存在风险" returned for datacenter IPs).
     */
    private function meting_with_fallback($api, $method, $id) {
        $raw = $api->format(true)->$method($id);
        $value = $this->extract_meting_url($raw);
        if ('' !== $value) {
            return $value;
        }
        return $this->meting_api_fallback($method, $id);
    }

    private function extract_meting_url($raw) {
        if (!is_string($raw) || '' === $raw) {
            return '';
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return '';
        }
        if (isset($json['code']) && (int) $json['code'] < 0) {
            return '';
        }
        return (isset($json['url']) && is_string($json['url'])) ? $json['url'] : '';
    }

    private function meting_api_fallback($type, $id) {
        $custom = trim((string) akina_option('meting_api_fallback'));
        $candidates = array();
        if ('' !== $custom) {
            $candidates[] = $custom;
        }
        $candidates[] = 'https://music-api.gdstudio.xyz/api.php';
        $candidates[] = 'https://api.injahow.cn/meting/';

        foreach ($candidates as $base) {
            $base = rtrim($base, '/');
            $is_gdstudio = (false !== strpos($base, 'gdstudio'));
            if ($is_gdstudio) {
                $url = $base . '?types=' . rawurlencode($type) . '&source=' . rawurlencode($this->server) . '&id=' . rawurlencode($id) . '&br=320';
            } else {
                $url = $base . '/?server=' . rawurlencode($this->server) . '&type=' . rawurlencode($type) . '&id=' . rawurlencode($id);
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_HEADER         => true,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; Sakura-theme Meting fallback)',
            ));
            $raw = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if (false === $raw || '' === $raw) {
                continue;
            }

            $body = $raw;
            $location = '';
            $split = strpos($raw, "\r\n\r\n");
            if (false !== $split) {
                $headers = substr($raw, 0, $split);
                $body = substr($raw, $split + 4);
                if (preg_match('/^location:\s*(\S+)/im', $headers, $m)) {
                    $location = trim($m[1]);
                }
            }

            if ($code >= 300 && $code < 400 && '' !== $location) {
                return $location;
            }

            $value = $this->extract_meting_url($body);
            if ('' !== $value) {
                return $value;
            }
        }
        return '';
    }

    private function format_playlist($data) {
        $server = $this->server;
        $api_url = $this->api_url;
        $data = json_decode($data);
        $playlist = array();
        foreach ((array)$data as $value) {
            $name = $value->name;
            $artists = implode(" / ", (array)$value->artist);
            $mp3_url = "$api_url?server=$server&type=url&id=" . $value->url_id . '&meting_nonce=' . wp_create_nonce('url#:' . $value->url_id);
            $cover = "$api_url?server=$server&type=pic&id=" . $value->pic_id . '&meting_nonce=' . wp_create_nonce('pic#:' . $value->url_id);
            $lyric = "$api_url?server=$server&type=lyric&id=" . $value->lyric_id . '&meting_nonce=' . wp_create_nonce('lyric#:' . $value->url_id);
            $playlist[] = array(
                "name" => $name,
                "artist" => $artists,
                "url" => $mp3_url,
                "cover" => $cover,
                "lrc" => $lyric
            );
        }
        return $playlist;
    }

    private function song_url($url){
        $server = $this->server;
        if ($server == 'netease') {
            $url = str_replace('://m7c.', '://m7.', $url);
            $url = str_replace('://m8c.', '://m8.', $url);
            $url = str_replace('http://m8.', 'https://m9.', $url);
            $url = str_replace('http://m7.', 'https://m9.', $url);
            $url = str_replace('http://m10.', 'https://m10.', $url);
        }elseif ($server == 'xiami') {
            $url = str_replace('http://', 'https://', $url);
        }elseif ($server == 'baidu') {
            $url = str_replace('http://zhangmenshiting.qianqian.com', 'https://gss3.baidu.com/y0s1hSulBw92lNKgpU_Z2jR7b2w6buu', $url);
        }else{
            $url = $url;
        }
        return $url;
    }

    private function format_lyric($data) {
        $server = $this->server;
        $data = json_decode($data, true);
        $data = $this->lrctran($data['lyric'], $data['tlyric']);
        if (empty($data)) {
            $data = "[00:00.000]此歌曲暂无歌词，请您欣赏";
        }
        if ($server === 'tencent') {
            $data = html_entity_decode($data, ENT_QUOTES | ENT_HTML5);
        }
        return $data;
    }

    private function lrctran($lyric, $tlyric) {
        $lyric = $this->lrctrim($lyric);
        $tlyric = $this->lrctrim($tlyric);
        $len1 = count($lyric);
        $len2 = count($tlyric);
        $result = "";
        for ($i = 0, $j = 0; $i < $len1 && $j < $len2; $i++) {
            while ($lyric[$i][0] > $tlyric[$j][0] && $j + 1 < $len2) {
                $j++;
            }
            if ($lyric[$i][0] == $tlyric[$j][0]) {
                $tlyric[$j][2] = str_replace('/', '', $tlyric[$j][2]);
                if (!empty($tlyric[$j][2])) {
                    $lyric[$i][2] .= " ({$tlyric[$j][2]})";
                }
                $j++;
            }
        }
        for ($i = 0; $i < $len1; $i++) {
            $t = $lyric[$i][0];
            $result .= sprintf("[%02d:%02d.%03d]%s\n", $t / 60000, $t % 60000 / 1000, $t % 1000, $lyric[$i][2]);
        }
        return $result;
    }

    private function lrctrim($lyrics) {
        $lyrics = explode("\n", $lyrics);
        $data = array();
        foreach ($lyrics as $key => $lyric) {
            preg_match('/\[(\d{2}):(\d{2}[\.:]?\d*)]/', $lyric, $lrcTimes);
            $lrcText = preg_replace('/\[(\d{2}):(\d{2}[\.:]?\d*)]/', '', $lyric);
            if (empty($lrcTimes)) {
                continue;
            }
            $lrcTimes = intval($lrcTimes[1]) * 60000 + intval(floatval($lrcTimes[2]) * 1000);
            $lrcText = preg_replace('/\s\s+/', ' ', $lrcText);
            $lrcText = trim($lrcText);
            $data[] = array($lrcTimes, $key, $lrcText);
        }
        sort($data);
        return $data;
    }
}