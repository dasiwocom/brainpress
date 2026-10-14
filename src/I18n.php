<?php
function language(): string { return $GLOBALS['brainpress_locale'] ??= (setting('language','en') === 'zh-CN' ? 'zh-CN' : 'en'); }
function translations(): array { static $map; return $map ??= json_decode(file_get_contents(dirname(__DIR__).'/locales/zh-CN.json'),true,512,JSON_THROW_ON_ERROR); }
function t(string $message): string { return language()==='zh-CN' ? (translations()[$message] ?? $message) : $message; }
function languageData(): string { return '<script id="translations" type="application/json">'.json_encode(language()==='zh-CN'?translations():new stdClass(),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE).'</script><script src="/assets/i18n.js?v=1" defer></script>'; }
