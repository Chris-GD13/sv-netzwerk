import { AstroLogger, type AstroLoggerDestination } from './core.js';
import type { LoggerHandlerConfig } from './config.js';
import type { AstroConfig, AstroInlineConfig } from '../../types/public/index.js';
export declare function loadLoggerDestination(config: LoggerHandlerConfig): Promise<AstroLoggerDestination>;
/**
 * It attempts to load a logger from the entrypoint.
 * If not provided, it creates a new logger instance on the fly.
 * @param astroConfig
 * @param inlineAstroConfig
 */
export declare function loadOrCreateNodeLogger(astroConfig: AstroConfig, inlineAstroConfig: AstroInlineConfig): Promise<AstroLogger>;
