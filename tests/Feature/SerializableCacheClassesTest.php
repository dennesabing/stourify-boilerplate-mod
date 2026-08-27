<?php

declare(strict_types=1);

use App\Cache\SerializableClassRegistry;
use App\Traits\Cacheable;
use Modules\Stourify\Models\Spot;
use Modules\Stourify\StourifyModule;

/**
 * The module's half of the cache allow-list (STOURIFY-216).
 *
 * A cache is a coat check: you hand an object over, and later a ticket brings
 * it back. PHP adds a rule to that — it will only rebuild a stored object if
 * the object's class is on a guest list, so that whoever can write into the
 * cache cannot name any class in `vendor/` and have PHP run its start-up code.
 *
 * The platform composes that guest list by asking every switched-on module for
 * its own names, through the optional `serializableCacheClasses()` method. This
 * module never declared it, so ten of its models were being cached and then
 * refused on the way back out. Refusal is silent: PHP hands back a
 * `__PHP_Incomplete_Class`, which survives every `try`/`catch` and only explodes
 * later, in unrelated code, on a cache hit.
 *
 * ## Why the module tests this itself
 *
 * The platform already has a drift guard that catches a missing name
 * (`Tests\Feature\Cache\SerializableClassCoverageTest`). That is the check that
 * found this bug, and it works. But it lives in a different repository, so the
 * feedback for adding an eleventh cacheable model to *this* module arrives from
 * somewhere the person adding it is not looking. These tests put the same
 * question inside the module that has to answer it.
 *
 * Membership is decided with `class_uses_recursive()` rather than a text search
 * for the trait's name, because a model can inherit `Cacheable` through a parent
 * class or through another trait and a grep misses every one of those.
 */

/**
 * Every concrete class under the module that uses `Cacheable`.
 *
 * The directory is found by asking the module class where it lives, so nothing
 * here hardcodes a path that a move would silently invalidate.
 *
 * @return list<class-string>
 */
function stourifyCacheableClasses(): array
{
    $directory = dirname((new ReflectionClass(StourifyModule::class))->getFileName());

    $found = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = file_get_contents($file->getPathname());

        if ($source === false) {
            continue;
        }

        if (! preg_match('/^namespace\s+([^;]+);/m', $source, $namespace)) {
            continue;
        }

        if (! preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', $source, $class)) {
            continue;
        }

        $fqcn = trim($namespace[1]).'\\'.$class[1];

        // A file that cannot be autoloaded is a file this application does not
        // run, and an abstract class is never instantiated, so nothing ever
        // serializes one. Its concrete children are found on their own.
        if (! class_exists($fqcn) || (new ReflectionClass($fqcn))->isAbstract()) {
            continue;
        }

        if (in_array(Cacheable::class, class_uses_recursive($fqcn), true)) {
            $found[] = $fqcn;
        }
    }

    sort($found);

    return $found;
}

test('the module publishes every cacheable class it owns', function () {
    $published = (new StourifyModule)->serializableCacheClasses();

    $missing = array_values(array_diff(stourifyCacheableClasses(), $published));

    expect($missing)->toBe([], sprintf(
        "These classes use Cacheable but this module does not publish them.\n".
        "Add them to StourifyModule::serializableCacheClasses():\n\n%s\n",
        implode("\n", array_map(fn (string $c): string => '    \\'.$c.'::class,', $missing)),
    ));
});

/**
 * The mirror of the test above, and the reason it means anything.
 *
 * A list that is allowed to name classes nobody caches drifts the other way:
 * every stale entry is a class PHP is permitted to rebuild for no benefit, and
 * the point of an allow-list is that each name on it was put there on purpose.
 */
test('the module publishes nothing it does not own', function () {
    $cacheable = stourifyCacheableClasses();

    expect($cacheable)->not->toBeEmpty('discovery found no Cacheable classes at all, so it proves nothing');

    $stale = array_values(array_diff((new StourifyModule)->serializableCacheClasses(), $cacheable));

    expect($stale)->toBe([], sprintf(
        "These classes are published but no longer use Cacheable. Remove them:\n\n%s\n",
        implode("\n", array_map(fn (string $c): string => '    \\'.$c.'::class,', $stale)),
    ));
});

/**
 * Publishing is only half the job — the names have to actually arrive.
 *
 * The platform merges each enabled module's list into the composed allow-list.
 * This asserts the whole path end to end, so a change to how modules are
 * discovered fails here rather than turning into a silent refusal on a tier.
 */
test('the published classes reach the composed allow-list', function () {
    $allowed = app(SerializableClassRegistry::class)->all();

    $notReaching = array_values(array_diff((new StourifyModule)->serializableCacheClasses(), $allowed));

    expect($notReaching)->toBe([]);
});

/**
 * The failure this card was filed for, reproduced as an assertion.
 *
 * Serializing and unserializing under the composed allow-list is exactly what a
 * real cache store does on a write and a read. Before the fix this returned
 * `__PHP_Incomplete_Class` and the next property access threw.
 */
test('a cached spot comes back as a real spot rather than an incomplete object', function () {
    // Raw attributes rather than a constructor array: what is being tested is
    // the round trip, and mass-assignment rules are a different subject that
    // would silently empty the object and make the assertion prove nothing.
    $spot = (new Spot)->setRawAttributes(['name' => 'Cache round trip']);

    $restored = unserialize(serialize($spot), [
        'allowed_classes' => app(SerializableClassRegistry::class)->all(),
    ]);

    expect($restored)->toBeInstanceOf(Spot::class)
        ->and($restored->name)->toBe('Cache round trip');
});
