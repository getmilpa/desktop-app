<?php

/**
 * This file is part of milpa/desktop-app — a Milpa app hosts itself as a desktop app.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/desktop-app
 */

declare(strict_types=1);

namespace Milpa\DesktopApp\Admin;

/*
 * The Desktop honors milpa/admin's `AdminSectionProvider` WITHOUT depending on milpa/admin (greenhouse
 * decisions/0210: the Desktop as the admin's guest).
 *
 * The admin discovers its guests by `instanceof Milpa\Admin\Section\AdminSectionProvider` over the kernel's
 * booted plugin instances (`SectionCatalogue::discover(Kernel::plugins())`), so the plugin CLASS itself must
 * carry that interface — a bridge object registered in the container would never be among the plugins, and
 * a second plugin the app has to declare is not «the Desktop is the guest». But a class that names an
 * interface which does not exist is a fatal at load time, and a fresh app WITHOUT milpa/admin must boot.
 *
 * So this file declares ONE interface, `AdminGuest`, in one of two shapes chosen the moment the autoloader
 * runs it — which PSR-4 does the first time `DesktopAppPlugin` (the class that implements it) is compiled:
 *
 *   - milpa/admin installed: `interface_exists()` autoloads the real `AdminSectionProvider`, and `AdminGuest`
 *     EXTENDS it. `DesktopAppPlugin instanceof AdminSectionProvider` is then true and the admin finds the
 *     Desktop with no registration step, exactly as it finds any plugin that imports the interface outright.
 *   - milpa/admin absent: `AdminGuest` is declared standalone with the same one method. The `instanceof` the
 *     admin would run is simply false — nothing fatals, and nothing in the admin's namespace is squatted (a
 *     stand-in declared under the admin's own name would shadow the real interface the day it is installed).
 *
 * Conditional declarations are ordinary PHP: an interface written inside an `if` exists once that branch has
 * run. Measured: a fresh app without milpa/admin boots and serves `/desktop` (tests/Admin/AdminAbsentBootTest).
 */
if (interface_exists(\Milpa\Admin\Section\AdminSectionProvider::class)) {
    /**
     * The Desktop as the admin's guest — milpa/admin's own contract, which the admin is installed to honor.
     *
     * Declared only when milpa/admin is present: `interface_exists()` autoloaded the real interface, so
     * `DesktopAppPlugin` is an `AdminSectionProvider` and the admin's `instanceof` discovery finds it.
     */
    interface AdminGuest extends \Milpa\Admin\Section\AdminSectionProvider
    {
    }
} else {
    /**
     * The Desktop as the admin's guest — the SHAPE of milpa/admin's `AdminSectionProvider`, declared when the
     * admin is not installed so the plugin class still compiles. Nothing calls it then: only the admin asks a
     * plugin for its sections, and there is no admin.
     */
    interface AdminGuest
    {
        /**
         * The sections this plugin contributes to the admin panel — `Milpa\Admin\Section\AdminSection`
         * instances when the admin is there to receive them.
         *
         * @return list<object>
         */
        public function adminSections(): array;
    }
}
