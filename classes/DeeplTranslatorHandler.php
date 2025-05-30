<?php

use DeepL\DocumentTranslationException;
use DeepL\TranslateTextOptions;
use DeepL\Translator;

class DeeplTranslatorHandler implements TranslatorHandlerInterface /*, TranslatorHandlerDocumentCapable*/
{
    use SiteDataStorageTrait;

    private $translator;

    public function getIdentifier(): string
    {
        return 'deepl';
    }

    public function getSettingsSchema(): array
    {
        return [
            "title" => "DeepL",
            "type" => "object",
            "properties" => [
                "key" => [
                    "type" => "string",
                    "title" => "Authentication key (from https://www.deepl.com/it/account/summary)",
                ],
                "usage" => [
                    "type" => "string",
                    "title" => "Account usage",
                    "readonly" => true,
                ],
            ],
        ];
    }

    public function getSettings(): array
    {
        $settings = (array)json_decode($this->getStorage('deepl_settings'), true);
        try {
            if ($settings['key']) {
                $settings['usage'] = (string)$this->getTranslator($settings['key'])->getUsage();
            }
        } catch (Throwable $e) {
            $settings['usage'] = (string)$e->getMessage();
        }
        return $settings;
    }

    public function storeSettings(array $settings): void
    {
        unset($settings['usage']);
        if (empty($settings['key'])){
            $this->deleteSettings();
        }else {
            $this->setStorage('deepl_settings', json_encode($settings));
        }
    }

    public function deleteSettings(): void
    {
        $this->removeStorage('deepl_settings');
    }

    public function translate(array $text, string $sourceLanguage, string $targetLanguage, array $options = []): array
    {
        $handlerOptions = [];
        if (in_array(TranslatorHandlerInterface::TRANSLATE_FROM_EZ_XML, $options)) {
            $handlerOptions = [
                TranslateTextOptions::TAG_HANDLING => 'xml',
                TranslateTextOptions::SPLITTING_TAGS => 'paragraph,c',
            ];
        }

        $translationResult = $this->getTranslator($this->getSettings()['key'])->translateText(
            $text,
            $this->mapLanguage($sourceLanguage, true),
            $this->mapLanguage($targetLanguage),
            $handlerOptions
        );

        return (array)$translationResult;
    }

    /**
     * @param array<int, eZBinaryFile[]> $inputFiles
     * @param ?string $sourceLanguage
     * @param string $targetLanguage
     * @param array $options
     * @return array<int, string[]>
     */
    public function translateDocument(
        array $inputFiles,
        ?string $sourceLanguage,
        string $targetLanguage,
        array $options = []
    ): array {
        $data = [];
        foreach ($inputFiles as $index => $files) {
            foreach ($files as $file) {
                $filename = $file->attribute('original_filename');
                $inputFilePath = $file->filePath();
                eZClusterFileHandler::instance($inputFilePath)->fetch();
                $outputFilePath = TranslatorManager::tempDir($file, $targetLanguage) . $targetLanguage . '_' . $filename;
                if (!file_exists($outputFilePath)) {
                    try {
                        $status = $this->getTranslator($this->getSettings()['key'])->translateDocument(
                            $inputFilePath,
                            $outputFilePath,
                            $this->mapLanguage($sourceLanguage, true),
                            $this->mapLanguage($targetLanguage)
                        );
                        if ($status->errorMessage) {
                            eZDebug::writeError($status->errorMessage, __METHOD__);
                        }
                    } catch (DocumentTranslationException $e) {
                        eZDebug::writeError($e->getMessage(), __METHOD__);
                    }
                }
                $data[$index][] = realpath($outputFilePath);
            }
        }
        return $data;
    }

    private function getTranslator($authKey): Translator
    {
        if ($this->translator === null) {
            $this->translator = new Translator($authKey);
        }

        return $this->translator;
    }

    public function isAllowedLanguage(string $languageCode): bool
    {
        try {
            $this->mapLanguage($languageCode);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function mapLanguage($languageCode, $asSourceLanguage = false): string
    {
        $map = [
//            '?' => 'bg',
            'cze-CZ' => 'cs',
//            '?' => 'da',
            'ger-DE' => 'de',
            'ell-GR' => 'el',
            'eng-GB' => 'en-GB',
            'esl-ES' => 'es',
//            '?' => 'et',
            'fin-FI' => 'fi',
            'fre-FR' => 'fr',
            'hun-HU' => 'hu',
            'ind-ID' => 'id',
            'ita-IT' => 'it',
            'jpn-JP' => 'ja',
//            '?' => 'ko',
//            '?' => 'lt',
//            '?' => 'lv',
            'nor-NO' => 'nb',
            'dut-NL' => 'nl',
            'pol-PL' => 'pl',
            'por-PT' => 'pt-PT',
            'por-BR' => 'pt-BR',
//            '?' => 'ro',
            'rus-RU' => 'ru',
            'slk-SK' => 'sk',
//            '?' => 'sl',
            'swe-SE' => 'sv',
            'tur-TR' => 'tr',
            'ukr-UA' => 'uk',
            'chi-CN' => 'zh',
        ];

        if (!isset($map[$languageCode])) {
            throw new RuntimeException("Language $languageCode not found in translator engine");
        }

        if ($asSourceLanguage){
            $language = explode('-', $map[$languageCode]);
            return $language[0];
        }

        return $map[$languageCode];
    }
}