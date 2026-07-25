import { type Flags } from '../flags.js';
interface DevOptions {
    flags: Flags;
}
/**
 * `yargs-parser` camel-cases `--ignore-lock` to `flags.ignoreLock`.
 */
export declare function isIgnoreLock(flags: Flags): boolean;
/**
 * `--ignore-lock` skips the lock file entirely, so a background dev server started with it
 * could never be found by `astro dev stop`/`status`/`logs`. Returns an error message if
 * background mode (explicit `--background`, or implied by AI agent detection) is combined
 * with `--ignore-lock`, or `null` if there's no conflict.
 */
export declare function getBackgroundIgnoreLockConflict(flags: Flags, wantsBackground: boolean): string | null;
/**
 * `--force` (replace the existing server) and `--ignore-lock` (start alongside it,
 * untracked) express contradictory intent. Returns an error message if both are set,
 * or `null` otherwise.
 */
export declare function getForceIgnoreLockConflict(flags: Flags): string | null;
export declare function dev({ flags }: DevOptions): Promise<import("../../core/dev/dev.js").DevServer | undefined>;
export {};
