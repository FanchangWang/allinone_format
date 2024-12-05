<?php

namespace Core;

class OutputManager
{
    private $configManager;
    private $logger;
    private $httpManager;
    private $m3uParser;
    private $channelManager;

    public function __construct()
    {
        $this->configManager = ConfigManager::getInstance();
        $this->logger = new LogManager();
        $this->httpManager = new HttpManager();
        $this->m3uParser = new M3uParser();
        $this->channelManager = new ChannelManager();
    }

    private function getM3uUrl($params)
    {
        $config = $this->configManager->getConfig();
        return isset($params['tv']) ? $params['tv'] : $config['tv_m3u_url'];
    }

    private function processM3uData($url)
    {
        if (empty($url)) {
            $this->logger->debug('未配置 tv.m3u 地址,尝试自动检测');
            $testUrl = $this->httpManager->detectTvM3uUrl($content);
            if ($testUrl) {
                $url = $testUrl;
                // 更新配置
                $config = $this->configManager->getConfig();
                $config['tv_m3u_url'] = $url;
                $this->configManager->updateConfig($config);
            } else {
                throw new \Exception('请配置 tv.m3u 地址!');
            }
        } else {
            $content = $this->httpManager->fetchContent($url);
            if (!$content) {
                throw new \Exception('获取tv.m3u内容失败 url: ' . $url);
            }
        }

        $m3uData = $this->m3uParser->parse($content);
        if (!$m3uData) {
            throw new \Exception('tv.m3u内容解析失败 url: ' . $url);
        }
        // $this->logger->debug("tv.m3u解析完成");
        // $this->logger->debug(json_encode($m3uData, JSON_UNESCAPED_UNICODE));

        // $m3uData = []; // 测试 tptv.m3u

        $m3uDataMerged = $m3uData;

        // 判断 $url 是否包含 /tv.m3u
        if (strpos($url, '/tv.m3u') !== false) {
            // 如果 config 中 fetch_tptv 为 true, 则抓取 tptv.m3u
            if ($this->configManager->getConfig()['fetch_tptv']) {
                // 将 $url 中的 tv.m3u 替换成 tptv.m3u
                $tptvUrl = str_replace('tv.m3u', 'tptv.m3u', $url);
                $content = $this->httpManager->fetchContent($tptvUrl);
                if (!$content) {
                    $this->logger->error('获取tptv.m3u内容失败 url: ' . $url);
                } else {
                    $m3uDataTptv = $this->m3uParser->parse($content);
                    if (!$m3uDataTptv) {
                        $this->logger->error('tptv.m3u内容解析失败 url: ' . $url);
                    } else {
                        // $this->logger->debug("tptv.m3u解析完成");
                        // $this->logger->debug(json_encode($m3uDataTptv, JSON_UNESCAPED_UNICODE));

                        // 合并 m3uData 和 m3uDataTptv
                        //但 link 相同的频道只保留 tv.m3u 的频道

                        // 提取 link 数组
                        $linkArray = array_column($m3uData, 'link');
                        foreach ($m3uDataTptv as $item) {
                            // 从 m3uData 中查找是否存在相同的 link
                            $index = array_search($item['link'], $linkArray);
                            if ($index === false) {
                                $m3uDataMerged[] = $item;
                            }
                        }
                        unset($linkArray);
                    }
                }
            }
            // 如果 config 中 fetch_migu 为 true, 则抓取 migu.m3u
            if ($this->configManager->getConfig()['fetch_migu']) {
                // 将 $url 中的 tv.m3u 替换成 migu.m3u
                $miguUrl = str_replace('tv.m3u', 'migu.m3u', $url);
                // 如果 config 中 migu_uid 和 migu_token 不为空, 则 ?userid=你的userid&usertoken=
                if ($this->configManager->getConfig()['migu_uid'] && $this->configManager->getConfig()['migu_token']) {
                    $miguUrl .= '?userid=' . $this->configManager->getConfig()['migu_uid'] . '&usertoken=' . $this->configManager->getConfig()['migu_token'];
                }
                $content = $this->httpManager->fetchContent($miguUrl);
                if (!$content) {
                    $this->logger->error('获取migu.m3u内容失败 url: ' . $url);
                } else {
                    $m3uDataMigu = $this->m3uParser->parse($content);
                    if (!$m3uDataMigu) {
                        $this->logger->error('migu.m3u内容解析失败 url: ' . $url);
                    } else {
                        // $this->logger->debug("migu.m3u解析完成");
                        // $this->logger->debug(json_encode($m3uDataMigu, JSON_UNESCAPED_UNICODE));

                        // 合并 m3uDataMerged 和 m3uDataMigu
                        // tv.m3u 中不包含 migu.m3u 的频道
                        foreach ($m3uDataMigu as $item) {
                            $m3uDataMerged[] = $item;
                        }
                    }
                }
            }
        }

        return $this->channelManager->format($m3uDataMerged);
    }

    private function generateJumpLink($link, $type, $desc)
    {
        $newDesc = '';
        // 判断 config 中 link_output_desc 是否为 true
        if ($this->configManager->getConfig()['link_output_desc']) {
            if (!empty($type)) {
                $newDesc = '$' . $type . ($desc ? '-' . $desc : '');
            }
        }

        // 判断 config 中 link_output_jump 是否为 true
        if (!$this->configManager->getConfig()['link_output_jump']) {
            return $link . $newDesc;
        }

        return $this->httpManager->getBaseUrl() . '/jump?url=' . urlencode($link) . $newDesc;
    }

    public function debugM3uData($params)
    {
        $url = $this->getM3uUrl($params);
        $data = $this->processM3uData($url);
        $result = [];
        foreach ($data['origin_grouped'] as $group => $channels) {
            // 获取 channels 的 name 值
            $names = array_column($channels, 'name');
            $result[$group] = $names;
        }
        // 将 debug_origin_grouped 添加到 data 头部
        $data = array_merge(['debug_origin_grouped' => $result], $data);
        return $data;
    }

    public function getM3uContent($format, $params)
    {
        try {
            $url = $this->getM3uUrl($params);
            $data = $this->processM3uData($url);

            if (!in_array($format, ['1', '2', '3'])) {
                $format = '1';
            }

            $output = "#EXTM3U x-tvg-url=\"https://epg.v1.mk/fy.xml\"\n";
            foreach ($data['output_grouped'] as $group => $channels) {
                foreach ($channels as $channel) {
                    $id = isset($channel['id']) ? (is_array($channel['id']) ? implode(',', $channel['id']) : $channel['id']) : '';
                    $name = isset($channel['name']) ? (is_array($channel['name']) ? implode(',', $channel['name']) : $channel['name']) : '';
                    $logo = isset($channel['logo']) ? (is_array($channel['logo']) ? implode(',', $channel['logo']) : $channel['logo']) : '';

                    if (isset($channel['urls']) && is_array($channel['urls'])) {
                        switch ($format) {
                            case '1':
                                // 格式1: 一个频道一个#EXTINF,所有链接在下面
                                $output .= "#EXTINF:-1 tvg-id=\"{$id}\" tvg-name=\"{$id}\" tvg-logo=\"{$logo}\" group-title=\"{$group}\",{$name}\n";
                                foreach ($channel['urls'] as $urlInfo) {
                                    if (isset($urlInfo['link'])) {
                                        $output .= $this->generateJumpLink($urlInfo['link'], $urlInfo['type'], $urlInfo['desc']) . "\n";
                                    }
                                }
                                break;

                            case '2':
                                // 格式2: 每个链接都有自己的#EXTINF
                                foreach ($channel['urls'] as $urlInfo) {
                                    if (isset($urlInfo['link'])) {
                                        $output .= "#EXTINF:-1 tvg-id=\"{$id}\" tvg-name=\"{$id}\" tvg-logo=\"{$logo}\" group-title=\"{$group}\",{$name}\n";
                                        $output .= $this->generateJumpLink($urlInfo['link'], $urlInfo['type'], $urlInfo['desc']) . "\n";
                                    }
                                }
                                break;

                            case '3':
                                // 格式3: 每个链接都有自己的#EXTINF,desc放在name后面
                                foreach ($channel['urls'] as $urlInfo) {
                                    if (isset($urlInfo['link'])) {
                                        $desc = isset($urlInfo['desc']) && !empty($urlInfo['desc']) ? '-' . $urlInfo['desc'] : '';
                                        $output .= "#EXTINF:-1 tvg-id=\"{$id}\" tvg-name=\"{$id}\" tvg-logo=\"{$logo}\" group-title=\"{$group}\",{$name}{$desc}\n";
                                        $output .= $this->generateJumpLink($urlInfo['link'], $urlInfo['type'], $urlInfo['desc']) . "\n";
                                    }
                                }
                                break;
                        }
                    }
                }
            }
            return $output;
        } catch (\Exception $e) {
            $this->logger->error('Get M3U content failed: ' . $e->getMessage());
            throw $e;
        }
    }

    public function getTxtContent($format, $params)
    {
        try {
            $url = $this->getM3uUrl($params);
            $data = $this->processM3uData($url);

            if (!in_array($format, ['1', '2', '3'])) {
                $format = '1';
            }

            $output = "";
            foreach ($data['output_grouped'] as $group => $channels) {
                if (empty($channels)) { // 如果频道组为空，则跳过
                    continue;
                }
                $output .= "\n" . $group . ",#genre#\n";
                foreach ($channels as $channel) {
                    $name = isset($channel['name']) ? (is_array($channel['name']) ? implode(',', $channel['name']) : $channel['name']) : '';

                    if (isset($channel['urls']) && is_array($channel['urls'])) {
                        switch ($format) {
                            case '1':
                                // 格式1: 一行显示所有链接,用#分隔
                                $urlStrings = [];
                                foreach ($channel['urls'] as $urlInfo) {
                                    if (isset($urlInfo['link'])) {
                                        $urlStrings[] = $this->generateJumpLink($urlInfo['link'], $urlInfo['type'], $urlInfo['desc']);
                                    }
                                }
                                $output .= $name . "," . implode("#", $urlStrings) . "\n";
                                break;

                            case '2':
                                // 格式2: 每个链接单独一行
                                foreach ($channel['urls'] as $urlInfo) {
                                    if (isset($urlInfo['link'])) {
                                        $output .= $name . "," . $this->generateJumpLink($urlInfo['link'], $urlInfo['type'], $urlInfo['desc']) . "\n";
                                    }
                                }
                                break;

                            case '3':
                                // 格式3: desc放在name后面
                                foreach ($channel['urls'] as $urlInfo) {
                                    if (isset($urlInfo['link'])) {
                                        $desc = isset($urlInfo['desc']) && !empty($urlInfo['desc']) ? '-' . $urlInfo['desc'] : '';
                                        $output .= $name . $desc . "," . $this->generateJumpLink($urlInfo['link'], null, null) . "\n";
                                    }
                                }
                                break;
                        }
                    }
                }
            }
            return $output;
        } catch (\Exception $e) {
            $this->logger->error('Get TXT content failed: ' . $e->getMessage());
            throw $e;
        }
    }
}
