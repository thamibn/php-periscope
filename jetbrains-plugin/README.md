# `jetbrains-plugin`

Custom Debug Adapter Protocol client for PhpStorm 2024.2+ that connects PhpStorm's
native debug toolbar (Step Over, Step Into, Step Out, **Step Back**, Resume, Stop)
to `periscope-daemon`.

**Not** built on LSP4IJ — we implement just the DAP requests periscope uses (~15)
directly in Kotlin, giving us full control over `stepBack` / `reverseContinue`
without inheriting LSP4IJ's ~20k LOC of language-server-protocol surface area.

## Build

```bash
cd jetbrains-plugin
./gradlew buildPlugin
# produces build/distributions/periscope-jetbrains-0.1.0.zip
```

## Sideload into PhpStorm

```bash
# macOS — copy into any installed PhpStorm's plugins/ dir
cp build/distributions/periscope-jetbrains-*.zip \
   ~/Library/Application\ Support/JetBrains/PhpStorm2024.3/plugins/
# restart PhpStorm
```

Or via `scripts/install.sh` once Phase F (install automation) lands.

## Layout

```
src/main/kotlin/dev/periscope/phpstorm/
├── dap/                        # transport — JSON-RPC over stdio
│   ├── DapClient.kt
│   ├── DapMessages.kt
│   └── DapEvents.kt          (folded into DapMessages.kt)
├── debugger/                   # JetBrains XDebugProcess glue
│   ├── PeriscopeDebugProcess.kt
│   ├── PeriscopeEditorsProvider.kt
│   ├── PeriscopeStackFrame.kt
│   ├── PeriscopeExecutionStack.kt
│   ├── PeriscopeSuspendContext.kt
│   ├── PeriscopeBreakpointHandler.kt
│   └── PeriscopeValue.kt
└── runconfig/                  # Run/Edit Configurations… UI
    ├── PeriscopeRunConfigurationType.kt
    ├── PeriscopeConfigurationFactory.kt
    ├── PeriscopeRunConfiguration.kt
    ├── PeriscopeRunConfigurationOptions.kt
    ├── PeriscopeSettingsEditor.kt
    └── PeriscopeDebuggerRunner.kt
```

## Compatibility

| | Min | Max |
|---|---|---|
| PhpStorm | 2024.2 (`242.*`) | 2025.1 (`251.*`) |
| Java | 17 | — |
| Kotlin | 2.0.21 | — |
| Gradle | 8.10 | — |
| IntelliJ Platform Gradle Plugin | 2.1.0 | — |

## License

Proprietary, same as the parent repo.
