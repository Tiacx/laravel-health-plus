<?php

namespace Tiacx\Health\Traits;

trait HasMessages
{
    /**
     * 设置消息模板（合并到现有模板）
     * @param array<string, string> $messages
     * @return $this
     */
    public function messages(array $messages): static
    {
        $this->messageTemplates = array_merge($this->messageTemplates, $messages);

        return $this;
    }

    /**
     * 获取填充后的消息
     * @param string $key
     * @param array<string, mixed> $params
     * @return string
     */
    protected function getMessage(string $key, array $params = []): string
    {
        $template = $this->messageTemplates[$key] ?? $key;

        foreach ($params as $placeholder => $value) {
            $template = str_replace('{' . $placeholder . '}', (string) $value, $template);
        }

        return $template;
    }
}
