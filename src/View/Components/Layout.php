<?php

namespace LonelyLights\Prosetta\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * Layout Component
 *
 * The main layout wrapper for Prosetta admin UI.
 *
 * @package LonelyLights\Prosetta\View\Components
 */
class Layout extends Component
{
    /**
     * The page title.
     *
     * @var string|null
     */
    public ?string $title;

    /**
     * Available locales.
     *
     * @var mixed
     */
    public $locales;

    /**
     * Current locale.
     *
     * @var string|null
     */
    public ?string $currentLocale;

    /**
     * Create a new component instance.
     *
     * @param string|null $title
     * @param mixed $locales
     * @param string|null $currentLocale
     */
    public function __construct(?string $title = null, $locales = null, ?string $currentLocale = null)
    {
        $this->title = $title;
        $this->locales = $locales;
        $this->currentLocale = $currentLocale;
    }

    /**
     * Get the view / contents that represent the component.
     *
     * @return View
     */
    public function render(): View
    {
        return view('prosetta::layouts.app');
    }
}
