<?php

/**
 * کلاس ساخت کیبورد برای تلگرام
 * این کلاس ساخت کیبوردهای inline و reply را ساده‌تر می‌کند
 */
class KeyboardBuilder
{
    private array $buttons = [];
    private bool $isInline = false;
    private bool $resizeKeyboard = true;
    private bool $oneTimeKeyboard = false;
    private bool $selective = false;

    /**
     * ساخت یک کیبورد جدید
     */
    public static function create(bool $inline = false): self
    {
        $builder = new self();
        $builder->isInline = $inline;
        return $builder;
    }

    /**
     * افزودن یک ردیف دکمه
     */
    public function addRow(array $buttons): self
    {
        $this->buttons[] = $buttons;
        return $this;
    }

    /**
     * افزودن یک دکمه
     */
    public function addButton(string $text, string $callbackData = null, string $url = null, string $switchInlineQuery = null): self
    {
        if ($this->isInline) {
            $button = ['text' => $text];
            
            if ($url) {
                $button['url'] = $url;
            } elseif ($switchInlineQuery !== null) {
                $button['switch_inline_query'] = $switchInlineQuery;
            } else {
                $button['callback_data'] = $callbackData ?? $text;
            }
            
            // اگر آخرین ردیف وجود ندارد یا خالی است، یک ردیف جدید اضافه کن
            if (empty($this->buttons) || empty(end($this->buttons))) {
                $this->buttons[] = [];
            }
            
            $lastIndex = count($this->buttons) - 1;
            $this->buttons[$lastIndex][] = $button;
        } else {
            // Reply keyboard
            if (empty($this->buttons) || empty(end($this->buttons))) {
                $this->buttons[] = [];
            }
            $lastIndex = count($this->buttons) - 1;
            $this->buttons[$lastIndex][] = ['text' => $text];
        }
        
        return $this;
    }

    /**
     * افزودن دکمه بازگشت
     */
    public function addBackButton(string $text = '◀️ بازگشت به منوی اصلی', string $callbackData = 'back_to_main_menu'): self
    {
        return $this->addRow([$this->createButton($text, $callbackData)]);
    }

    /**
     * ساخت دکمه
     */
    private function createButton(string $text, string $callbackData = null, string $url = null): array
    {
        $button = ['text' => $text];
        
        if ($this->isInline) {
            if ($url) {
                $button['url'] = $url;
            } else {
                $button['callback_data'] = $callbackData ?? $text;
            }
        }
        
        return $button;
    }

    /**
     * افزودن دکمه‌های شبکه‌ای (Grid)
     */
    public function addGrid(array $buttons, int $columns = 2): self
    {
        $rows = array_chunk($buttons, $columns);
        foreach ($rows as $row) {
            $this->addRow($row);
        }
        return $this;
    }

    /**
     * تنظیم resize keyboard
     */
    public function setResizeKeyboard(bool $resize): self
    {
        $this->resizeKeyboard = $resize;
        return $this;
    }

    /**
     * تنظیم one time keyboard
     */
    public function setOneTimeKeyboard(bool $oneTime): self
    {
        $this->oneTimeKeyboard = $oneTime;
        return $this;
    }

    /**
     * تنظیم selective
     */
    public function setSelective(bool $selective): self
    {
        $this->selective = $selective;
        return $this;
    }

    /**
     * دریافت کیبورد نهایی
     */
    public function build(): array
    {
        if ($this->isInline) {
            return ['inline_keyboard' => $this->buttons];
        } else {
            return [
                'keyboard' => $this->buttons,
                'resize_keyboard' => $this->resizeKeyboard,
                'one_time_keyboard' => $this->oneTimeKeyboard,
                'selective' => $this->selective
            ];
        }
    }

    /**
     * دریافت JSON کیبورد
     */
    public function toJson(): string
    {
        return json_encode($this->build(), JSON_UNESCAPED_UNICODE);
    }

    /**
     * حذف کیبورد
     */
    public static function remove(): array
    {
        return ['remove_keyboard' => true];
    }

    /**
     * ساخت کیبورد ساده با یک ردیف
     */
    public static function simple(array $buttons, bool $inline = false): array
    {
        $builder = self::create($inline);
        $builder->addRow($buttons);
        return $builder->build();
    }

    /**
     * ساخت کیبورد شبکه‌ای
     */
    public static function grid(array $buttons, int $columns = 2, bool $inline = false): array
    {
        $builder = self::create($inline);
        $builder->addGrid($buttons, $columns);
        return $builder->build();
    }
}
