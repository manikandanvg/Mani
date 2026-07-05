<?php

/*
 * L-BOX device platform (internal-only, LORD Jeweller).
 *
 * TTS: free, self-hosted, per-language engines. The server renders each voice
 * line to a cached WAV; the box streams it (MAX98357). If an engine is missing
 * the announcement still delivers as text and the box falls back to beeps.
 *   - piper   : neural TTS, natural English (https://github.com/rhasspy/piper via pip piper-tts)
 *   - espeak  : eSpeak-NG, robotic but tiny and speaks Tamil TODAY
 * Upgrade path for premium Tamil: AI4Bharat Indic-TTS behind the 'command' engine.
 */
return [
    'tts' => [
        'enabled' => env('LBOX_TTS_ENABLED', true),

        // language code => engine key below
        'engines' => [
            'en' => env('LBOX_TTS_ENGINE_EN', 'piper'),
            'ta' => env('LBOX_TTS_ENGINE_TA', 'espeak'),
        ],

        'piper' => [
            // pip install piper-tts → runnable as `python -m piper`
            'command' => env('LBOX_PIPER_CMD', env('LBOX_STT_PYTHON', 'python') . ' -m piper'),
            'voices' => [
                'en' => env('LBOX_PIPER_VOICE_EN', storage_path('app/lbox-tts/en_US-lessac-medium.onnx')),
            ],
        ],

        'espeak' => [
            'bin' => env('LBOX_ESPEAK_BIN', 'C:\\Program Files\\eSpeak NG\\espeak-ng.exe'),
            // language code => espeak voice
            'voices' => ['ta' => 'ta', 'en' => 'en-us'],
            'speed_wpm' => 150,
        ],

        // Free-form escape hatch: {text} {out} placeholders (e.g. AI4Bharat wrapper script)
        'command' => [
            'template' => env('LBOX_TTS_COMMAND'),   // e.g. 'python D:\lbox\tts\indic.py --lang {lang} --text {text} --out {out}'
        ],
    ],

    /*
     * STT: self-hosted faster-whisper (pip install faster-whisper). The 'small'
     * multilingual model (~460MB, auto-downloaded on first use) handles Tamil +
     * English commands fine on CPU. scripts/lbox_stt.py is the bridge.
     */
    'stt' => [
        'enabled' => env('LBOX_STT_ENABLED', true),
        'python' => env('LBOX_STT_PYTHON', 'python'),
        'pythonpath' => env('LBOX_STT_PYTHONPATH'),   // per-user pip site-packages, for the web-server account
        'model' => env('LBOX_STT_MODEL', 'small'),
        'timeout' => env('LBOX_STT_TIMEOUT', 300),   // first run downloads the model
    ],
];
