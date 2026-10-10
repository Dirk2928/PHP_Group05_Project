<?php

$root = $argv[1] ?? '';

if ($root === '' || !is_dir($root)) {
    fwrite(STDERR, "usage: php strip_comments.php <directory>\n");
    exit(1);
}

$extensions = ['php', 'js', 'css', 'sql'];
$changed = [];
$failed = [];

$directory = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
$iterator = new RecursiveIteratorIterator($directory);

foreach ($iterator as $file) {
    if (!$file->isFile()) {
        continue;
    }

    $path = $file->getPathname();
    $normalized = str_replace('\\', '/', $path);

    if (strpos($normalized, '/node_modules/') !== false) {
        continue;
    }

    if (strpos($normalized, '/.git/') !== false) {
        continue;
    }

    $extension = strtolower($file->getExtension());

    if (!in_array($extension, $extensions, true)) {
        continue;
    }

    $source = file_get_contents($path);

    if ($source === false) {
        $failed[] = $path . ' (unreadable)';
        continue;
    }

    if ($extension === 'php') {
        $stripped = strip_php_comments($source);
    } else {
        $stripped = strip_script_comments($source, $extension);
    }

    if ($stripped === null) {
        $failed[] = $path . ' (stripper error)';
        continue;
    }

    if ($stripped !== $source) {
        if (file_put_contents($path, $stripped) === false) {
            $failed[] = $path . ' (unwritable)';
            continue;
        }

        $changed[] = $normalized;
    }
}

echo 'changed: ' . count($changed) . "\n";

foreach ($changed as $path) {
    echo '  ' . $path . "\n";
}

if ($failed) {
    echo 'failed: ' . count($failed) . "\n";

    foreach ($failed as $path) {
        echo '  ' . $path . "\n";
    }
}

exit($failed ? 1 : 0);

function strip_php_comments(string $source): ?string
{
    $tokens = @token_get_all($source);

    if (!$tokens) {
        return null;
    }

    $parts = [];

    foreach ($tokens as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                $parts[] = ['comment', $token[1]];
                continue;
            }

            if ($token[0] === T_INLINE_HTML) {
                $parts[] = ['html', strip_inline_markup($token[1])];
                continue;
            }

            $parts[] = ['code', $token[1]];
            continue;
        }

        $parts[] = ['code', $token];
    }

    return join_php_parts($parts);
}

function strip_inline_markup(string $html): string
{
    $html = strip_html_comments($html);
    $html = strip_inline_blocks($html, 'style', 'css');
    $html = strip_inline_blocks($html, 'script', 'js');

    return $html;
}

function strip_inline_blocks(string $html, string $tag, string $extension): string
{
    $output = '';
    $offset = 0;

    while (true) {
        $openStart = stripos($html, '<' . $tag, $offset);

        if ($openStart === false) {
            $output .= substr($html, $offset);
            break;
        }

        $openEnd = strpos($html, '>', $openStart);

        if ($openEnd === false) {
            $output .= substr($html, $offset);
            break;
        }

        $closeStart = stripos($html, '</' . $tag, $openEnd);

        if ($closeStart === false) {
            $output .= substr($html, $offset);
            break;
        }

        $output .= substr($html, $offset, $openEnd + 1 - $offset);

        $inner = substr($html, $openEnd + 1, $closeStart - $openEnd - 1);
        $stripped = strip_script_comments($inner, $extension);

        $output .= $stripped === null ? $inner : $stripped;
        $offset = $closeStart;
    }

    return $output;
}

function strip_html_comments(string $html): string
{
    $output = '';
    $offset = 0;
    $length = strlen($html);

    while ($offset < $length) {
        $start = strpos($html, '<!--', $offset);

        if ($start === false) {
            $output .= substr($html, $offset);
            break;
        }

        $end = strpos($html, '-->', $start + 4);

        if ($end === false) {
            $output .= substr($html, $offset);
            break;
        }

        $output .= substr($html, $offset, $start - $offset);
        $output .= preg_replace('/[^\r\n]/', '', substr($html, $start, $end + 3 - $start));
        $offset = $end + 3;
    }

    return $output;
}

function join_php_parts(array $parts): string
{
    $output = '';
    $lastWasCommentBreak = false;

    foreach ($parts as $part) {
        [$kind, $text] = $part;

        if ($kind === 'comment') {
            if ($lastWasCommentBreak) {
                continue;
            }

            if (strpos($text, "\n") === false) {
                continue;
            }

            $output = preg_replace('/[ \t]+$/', '', $output);
            $output .= "\n";
            $lastWasCommentBreak = true;
            continue;
        }

        $isIndent = trim($text) === '' && strpos($text, "\n") === false;

        if ($isIndent && $lastWasCommentBreak) {
            continue;
        }

        if (!$isIndent) {
            $lastWasCommentBreak = false;
        }

        $output .= $text;
    }

    return $output;
}

function strip_script_comments(string $source, string $extension): ?string
{
    $length = strlen($source);
    $output = '';
    $offset = 0;
    $lastSignificant = '';

    $lineSlash = $extension === 'js';
    $lineDash = $extension === 'sql';
    $backticks = $extension === 'js' || $extension === 'sql';

    while ($offset < $length) {
        $character = $source[$offset];

        if ($character === "'" || $character === '"' || ($backticks && $character === '`')) {
            $quote = $character;
            $output .= $character;
            $offset++;

            while ($offset < $length) {
                $current = $source[$offset];
                $output .= $current;
                $offset++;

                if ($current === '\\') {
                    if ($offset < $length) {
                        $output .= $source[$offset];
                        $offset++;
                    }

                    continue;
                }

                if ($current === $quote) {
                    if ($offset < $length && $source[$offset] === $quote) {
                        $output .= $source[$offset];
                        $offset++;
                        continue;
                    }

                    break;
                }
            }

            $lastSignificant = 'value';
            continue;
        }

        if ($character === '/' && $offset + 1 < $length && $source[$offset + 1] === '*') {
            $end = strpos($source, '*/', $offset + 2);
            $end = $end === false ? $length : $end + 2;
            $comment = substr($source, $offset, $end - $offset);
            $output .= comment_replacement($comment);
            $offset = $end;
            continue;
        }

        if ($lineSlash && $character === '/' && $offset + 1 < $length && $source[$offset + 1] === '/') {
            $end = strpos($source, "\n", $offset);

            if ($end === false) {
                break;
            }

            $output = preg_replace('/[ \t]+$/', '', $output);
            $output .= "\n";
            $offset = $end + 1;
            continue;
        }

        if ($lineDash && $character === '-' && $offset + 1 < $length && $source[$offset + 1] === '-') {
            $after = $offset + 2 < $length ? $source[$offset + 2] : "\n";

            if ($after === ' ' || $after === "\t" || $after === "\n" || $after === "\r") {
                $end = strpos($source, "\n", $offset);

                if ($end === false) {
                    break;
                }

                $output = preg_replace('/[ \t]+$/', '', $output);
                $output .= "\n";
                $offset = $end + 1;
                continue;
            }
        }

        if ($extension === 'js' && $character === '/' && is_regex_start($lastSignificant)) {
            $regex = read_regex($source, $offset);

            if ($regex !== null) {
                $output .= $regex;
                $offset += strlen($regex);
                $lastSignificant = 'value';
                continue;
            }
        }

        $output .= $character;

        if (!ctype_space($character)) {
            $lastSignificant = $character;
        }

        $offset++;
    }

    return $output;
}

function comment_replacement(string $comment): string
{
    if (strpos($comment, "\n") === false) {
        return '';
    }

    return preg_replace('/[^\r\n]/', '', $comment);
}

function is_regex_start(string $lastSignificant): bool
{
    if ($lastSignificant === '') {
        return true;
    }

    return strpos('(,=:[!&|?{};+-*%~^<>', $lastSignificant) !== false;
}

function read_regex(string $source, int $offset): ?string
{
    $length = strlen($source);
    $index = $offset + 1;
    $inClass = false;

    while ($index < $length) {
        $character = $source[$index];

        if ($character === '\\') {
            $index += 2;
            continue;
        }

        if ($character === "\n") {
            return null;
        }

        if ($character === '[') {
            $inClass = true;
        } elseif ($character === ']') {
            $inClass = false;
        } elseif ($character === '/' && !$inClass) {
            $index++;

            while ($index < $length && ctype_alpha($source[$index])) {
                $index++;
            }

            return substr($source, $offset, $index - $offset);
        }

        $index++;
    }

    return null;
}
