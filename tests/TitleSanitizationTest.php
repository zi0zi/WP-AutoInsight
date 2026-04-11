<?php

abcc_test(
    'sanitize title strips Chinese preamble and returns actual title',
    function () {
        $result = array(
            '好的，这里为您提供一些针对您提供的主题的标题建议：',
            '如何利用人工智能提升工作效率',
        );

        $title = abcc_sanitize_ai_title($result);
        abcc_assert_same('如何利用人工智能提升工作效率', $title);
    }
);

abcc_test(
    'sanitize title strips "当然，以下是" preamble',
    function () {
        $result = array(
            '当然，以下是一些围绕您提供的主题生成的吸引人中文博客文章标题，并根据不同侧重点进行了分类：',
            '',
            '1. 深度解析：ChatGPT的核心技术与未来趋势',
        );

        $title = abcc_sanitize_ai_title($result);
        abcc_assert_same('深度解析：ChatGPT的核心技术与未来趋势', $title);
    }
);

abcc_test(
    'sanitize title handles clean single-line title without stripping',
    function () {
        $result = array('探索宇宙奥秘：从黑洞到暗物质');

        $title = abcc_sanitize_ai_title($result);
        abcc_assert_same('探索宇宙奥秘：从黑洞到暗物质', $title);
    }
);

abcc_test(
    'sanitize title strips markdown heading markers',
    function () {
        $result = array('## **探索AI的无限可能**');

        $title = abcc_sanitize_ai_title($result);
        abcc_assert_same('探索AI的无限可能', $title);
    }
);

abcc_test(
    'sanitize title strips surrounding quotes',
    function () {
        $result = array('"数字化转型：企业的必经之路"');

        $title = abcc_sanitize_ai_title($result);
        abcc_assert_same('数字化转型：企业的必经之路', $title);
    }
);

abcc_test(
    'sanitize title strips English preamble',
    function () {
        $result = array(
            'Sure, here is a title for your blog post:',
            'The Future of Artificial Intelligence',
        );

        $title = abcc_sanitize_ai_title($result);
        abcc_assert_same('The Future of Artificial Intelligence', $title);
    }
);

abcc_test(
    'sanitize title falls back to last line when all lines are preamble',
    function () {
        $result = array(
            '好的，让我为您生成一个标题',
            '以下是建议的标题供您参考',
        );

        // All lines match preamble, so it should fallback to the last non-empty line.
        $title = abcc_sanitize_ai_title($result);
        abcc_assert_true(! empty($title), 'Title should not be empty even when all lines look like preamble');
    }
);

abcc_test(
    'sanitize title handles string input',
    function () {
        $result = "好的，这里为您提供一个标题\n掌握Python编程的十个技巧";

        $title = abcc_sanitize_ai_title($result);
        abcc_assert_same('掌握Python编程的十个技巧', $title);
    }
);

abcc_test(
    'sanitize title strips bullet list prefix',
    function () {
        $result = array('- 区块链技术如何改变金融行业');

        $title = abcc_sanitize_ai_title($result);
        abcc_assert_same('区块链技术如何改变金融行业', $title);
    }
);
