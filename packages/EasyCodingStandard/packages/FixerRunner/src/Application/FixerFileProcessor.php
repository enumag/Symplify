<?php declare(strict_types=1);

namespace Symplify\EasyCodingStandard\FixerRunner\Application;

use Nette\Utils\FileSystem;
use PhpCsFixer\Differ\DifferInterface;
use PhpCsFixer\Fixer\FixerInterface;
use PhpCsFixer\Tokenizer\Tokens;
use Symplify\EasyCodingStandard\Application\CurrentFileProvider;
use Symplify\EasyCodingStandard\Configuration\Configuration;
use Symplify\EasyCodingStandard\Contract\Application\FileProcessorInterface;
use Symplify\EasyCodingStandard\Error\ErrorAndDiffCollector;
use Symplify\EasyCodingStandard\FileSystem\CachedFileLoader;
use Symplify\EasyCodingStandard\FixerRunner\Exception\Application\FixerFailedException;
use Symplify\EasyCodingStandard\FixerRunner\Parser\FileToTokensParser;
use Symplify\EasyCodingStandard\Skipper;
use Symplify\PackageBuilder\FileSystem\SmartFileInfo;
use Throwable;
use function Safe\sprintf;
use function Safe\usort;

final class FixerFileProcessor implements FileProcessorInterface
{
    /**
     * @var FixerInterface[]
     */
    private $fixers = [];

    /**
     * @var ErrorAndDiffCollector
     */
    private $errorAndDiffCollector;

    /**
     * @var Skipper
     */
    private $skipper;

    /**
     * @var Configuration
     */
    private $configuration;

    /**
     * @var FileToTokensParser
     */
    private $fileToTokensParser;

    /**
     * @var CachedFileLoader
     */
    private $cachedFileLoader;

    /**
     * @var DifferInterface
     */
    private $differ;

    /**
     * @var CurrentFileProvider
     */
    private $currentFileProvider;

    /**
     * @param FixerInterface[] $fixers
     */
    public function __construct(
        ErrorAndDiffCollector $errorAndDiffCollector,
        Configuration $configuration,
        FileToTokensParser $fileToTokensParser,
        CachedFileLoader $cachedFileLoader,
        Skipper $skipper,
        DifferInterface $differ,
        CurrentFileProvider $currentFileProvider,
        array $fixers
    ) {
        $this->errorAndDiffCollector = $errorAndDiffCollector;
        $this->skipper = $skipper;
        $this->configuration = $configuration;
        $this->fileToTokensParser = $fileToTokensParser;
        $this->cachedFileLoader = $cachedFileLoader;
        $this->differ = $differ;
        $this->currentFileProvider = $currentFileProvider;
        $this->fixers = $this->sortFixers($fixers);
    }

    /**
     * @return FixerInterface[]
     */
    public function getCheckers(): array
    {
        return $this->fixers;
    }

    public function processFile(SmartFileInfo $smartFileInfo): string
    {
        $this->currentFileProvider->setFileInfo($smartFileInfo);

        $oldContent = $this->cachedFileLoader->getFileContent($smartFileInfo);

        $tokens = $this->fileToTokensParser->parseFromFilePath($smartFileInfo->getRealPath());

        $appliedFixers = [];

        foreach ($this->getCheckers() as $fixer) {
            if ($this->shouldSkip($smartFileInfo, $fixer, $tokens)) {
                continue;
            }

            try {
                $fixer->fix($smartFileInfo, $tokens);
            } catch (Throwable $throwable) {
                throw new FixerFailedException(sprintf(
                    'Fixing of "%s" file by "%s" failed: %s in file %s on line %d',
                    $smartFileInfo->getRelativeFilePath(),
                    get_class($fixer),
                    $throwable->getMessage(),
                    $throwable->getFile(),
                    $throwable->getLine()
                ), $throwable->getCode(), $throwable);
            }

            if (! $tokens->isChanged()) {
                continue;
            }

            $tokens->clearEmptyTokens();
            $tokens->clearChanged();
            $appliedFixers[] = get_class($fixer);
        }

        if (! $appliedFixers) {
            return $oldContent;
        }

        $diff = $this->differ->diff($oldContent, $tokens->generateCode());
        // some fixer with feature overlap can null each other
        if ($diff === '') {
            return $oldContent;
        }

        // file has changed
        $this->errorAndDiffCollector->addDiffForFileInfo($smartFileInfo, $diff, $appliedFixers);

        if ($this->configuration->isFixer()) {
            FileSystem::write($smartFileInfo->getRealPath(), $tokens->generateCode());
        }

        Tokens::clearCache();

        return $tokens->generateCode();
    }

    /**
     * @param FixerInterface[] $fixers
     * @return FixerInterface[]
     */
    private function sortFixers(array $fixers): array
    {
        usort($fixers, function (FixerInterface $firstFixer, FixerInterface $secondFixer): int {
            return $secondFixer->getPriority() <=> $firstFixer->getPriority();
        });

        return $fixers;
    }

    private function shouldSkip(SmartFileInfo $smartFileInfo, FixerInterface $fixer, Tokens $tokens): bool
    {
        if ($this->skipper->shouldSkipCheckerAndFile($fixer, $smartFileInfo)) {
            return true;
        }

        return ! $fixer->supports($smartFileInfo) || ! $fixer->isCandidate($tokens);
    }
}
