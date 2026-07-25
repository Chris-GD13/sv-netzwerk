import { AstroLogger } from "./core.js";
import { AstroError } from "../errors/index.js";
import { UnableToLoadLogger } from "../errors/errors-data.js";
import { default as nodeLoggerCreator, createNodeLoggerFromFlags } from "./impls/node.js";
import { default as consoleLoggerCreator } from "./impls/console.js";
import { default as jsonLoggerCreator } from "./impls/json.js";
import { default as composeLoggerCreator } from "./impls/compose.js";
function normalizeEntrypoint(entrypoint) {
  return entrypoint instanceof URL ? entrypoint.href : entrypoint;
}
async function loadLoggerDestination(config) {
  let cause = void 0;
  const entrypoint = normalizeEntrypoint(config.entrypoint);
  try {
    switch (config.entrypoint) {
      case "astro/logger/node": {
        return nodeLoggerCreator(config.config);
      }
      case "astro/logger/console": {
        return consoleLoggerCreator(config.config);
      }
      case "astro/logger/json": {
        return jsonLoggerCreator(config.config);
      }
      case "astro/logger/compose": {
        let destinations = [];
        if (config.config?.loggers) {
          const loggers = config.config?.loggers;
          destinations = await Promise.all(
            loggers.map(async (loggerConfig) => {
              const logger = await import(
                /* @vite-ignore */
                normalizeEntrypoint(loggerConfig.entrypoint)
              );
              return logger.default(loggerConfig.config);
            })
          );
        }
        return composeLoggerCreator(destinations);
      }
      default: {
        const logger = await import(
          /* @vite-ignore */
          entrypoint
        );
        return logger.default(config.config);
      }
    }
  } catch (e) {
    if (e instanceof Error) {
      cause = e;
    }
  }
  const error = new AstroError({
    ...UnableToLoadLogger,
    message: UnableToLoadLogger.message(entrypoint)
  });
  if (cause) {
    error.cause = cause;
  }
  throw error;
}
async function loadOrCreateNodeLogger(astroConfig, inlineAstroConfig) {
  if (inlineAstroConfig._logger) return inlineAstroConfig._logger;
  try {
    if (astroConfig.logger) {
      return new AstroLogger({
        destination: await loadLoggerDestination(astroConfig.logger),
        level: inlineAstroConfig.logLevel ?? "info"
      });
    } else {
      return createNodeLoggerFromFlags(inlineAstroConfig);
    }
  } catch {
    return createNodeLoggerFromFlags(inlineAstroConfig);
  }
}
export {
  loadLoggerDestination,
  loadOrCreateNodeLogger
};
