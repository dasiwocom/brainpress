<?php
declare(strict_types=1);

namespace Brainpress;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Extension\ExtensionInterface;
use League\CommonMark\Node\Inline\AbstractStringContainer;
use League\CommonMark\Node\Block\AbstractBlock;
use League\CommonMark\Node\Node;
use League\CommonMark\Parser\Inline\InlineParserInterface;
use League\CommonMark\Parser\Inline\InlineParserMatch;
use League\CommonMark\Parser\InlineParserContext;
use League\CommonMark\Parser\Cursor;
use League\CommonMark\Parser\MarkdownParserStateInterface;
use League\CommonMark\Parser\Block\{AbstractBlockContinueParser,BlockContinue,BlockContinueParserInterface,BlockStart,BlockStartParserInterface};
use League\CommonMark\Renderer\{NodeRendererInterface,ChildNodeRendererInterface};
use League\CommonMark\Util\HtmlElement;
use League\CommonMark\Util\Xml;

final class MathInline extends AbstractStringContainer { public bool $display = false; }
final class MathBlock extends AbstractBlock { public string $literal = ''; }
final class MathInlineParser implements InlineParserInterface {
    public function getMatchDefinition(): InlineParserMatch { return InlineParserMatch::oneOf('$$', '$', '\\(', '\\['); }
    public function parse(InlineParserContext $context): bool {
        $cursor = $context->getCursor(); $open = $context->getFullMatch();
        $close = ['\\(' => '\\)', '\\[' => '\\]'][$open] ?? $open;
        $text = $cursor->getRemainder(); $offset = strlen($open);
        if ($open === '$' && (!isset($text[1]) || ctype_space($text[1]))) return false;
        while (($end = strpos($text, $close, $offset)) !== false) {
            $slashes = 0; for ($i=$end-1; $i>=0 && $text[$i]==='\\'; $i--) $slashes++;
            if ($slashes % 2) { $offset = $end+strlen($close); continue; }
            $body = substr($text, strlen($open), $end-strlen($open));
            if ($body === '' || ($open === '$' && (str_contains($body,"\n") || ctype_space(substr($body,-1)) || (isset($text[$end+1]) && ctype_digit($text[$end+1]))))) return false;
            $node = new MathInline($body); $node->display = $open === '$$' || $open === '\\[';
            $context->getContainer()->appendChild($node);
            $cursor->advanceBy(mb_strlen(substr($text,0,$end+strlen($close)))); return true;
        }
        return false;
    }
}
final class MathBlockStart implements BlockStartParserInterface {
    public function tryStart(Cursor $cursor, MarkdownParserStateInterface $parserState): ?BlockStart {
        if ($cursor->isIndented()) return BlockStart::none();
        $line = trim($cursor->getRemainder());
        if (!in_array($line, ['$$','\\['], true)) return BlockStart::none();
        $cursor->advanceToEnd();
        return BlockStart::of(new MathBlockParser($line === '$$' ? '$$' : '\\]'))->at($cursor);
    }
}
final class MathBlockParser extends AbstractBlockContinueParser {
    private MathBlock $block; private array $lines=[];
    public function __construct(private string $close) { $this->block = new MathBlock(); }
    public function getBlock(): AbstractBlock { return $this->block; }
    public function tryContinue(Cursor $cursor, BlockContinueParserInterface $activeBlockParser): ?BlockContinue {
        return trim($cursor->getRemainder()) === $this->close ? BlockContinue::finished() : BlockContinue::at($cursor);
    }
    public function addLine(string $line): void { $this->lines[] = $line; }
    public function closeBlock(): void { $this->block->literal = trim(implode("\n",$this->lines)); }
}
final class MathRenderer implements NodeRendererInterface {
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): \Stringable {
        $block = $node instanceof MathBlock;
        $literal = $block ? $node->literal : $node->getLiteral();
        return new HtmlElement($block ? 'div' : 'span', ['class'=>'math-source','data-display'=>($block || $node->display) ? 'true' : 'false'], Xml::escape($literal));
    }
}
final class MathExtension implements ExtensionInterface {
    public function register(EnvironmentBuilderInterface $environment): void {
        $environment->addInlineParser(new MathInlineParser(), 250);
        $environment->addBlockStartParser(new MathBlockStart(), 250);
        $environment->addRenderer(MathInline::class, new MathRenderer());
        $environment->addRenderer(MathBlock::class, new MathRenderer());
    }
}
