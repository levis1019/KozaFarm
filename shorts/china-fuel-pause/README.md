# Quick Why · China paused its fuel exports

A 39.9-second vertical Short (1080×1920) for the Quick Why channel. Built with the
[motion-design-skill](https://github.com/Ismyp/motion-design-skill) toolchain (MIT).

- `quick-why-china-fuel-pause.mp4`: the finished Short (H.264/AAC, −14 LUFS).
- `thumbnail.jpg`: the chosen thumbnail (1080×1920), drawn by `film/thumb.html` with the film's own helpers.
- `skript.txt`: the approved voiceover script, one line per beat.
- `film/film.html`: the film. One 1080×1920 canvas; `window.FILM.seek(t)` draws frame t as a pure function of t.
  All visual timings come from `film/zeiten.js` (key times derived from measured word times).
- `zeiten.py`: derives the key times from `out/vo.json` and writes `film/zeiten.js` and `out/zeiten.json`.
- `ton/partitur.py`: music and sound effects composed on the key times.
- `out/vo.flac`, `out/vo.json`: the recorded voice (stock synthetic voice "Achird", Gemini 2.5 Pro TTS via kie.ai) and its word times.
- `tools/`: map-data preparation (Natural Earth → projected polylines), vertical contact sheets.

Rebuild (from a project folder created by the skill, with the skill's tools installed):

    python3 zeiten.py
    python3 ton/partitur.py
    bash "$SKILL/werkzeuge/mix.sh" out/vo.wav out/musik.wav out/sfx.wav
    node "$SKILL/werkzeuge/render.mjs" film/film.html --ohne-untertitel --out=out/film.mp4 --audio=out/mix.wav --workers=1

## Sources (one per factual line)

1. Reuters, Oct 1, 2026: https://live.euronext.com/en/financial-news/chinese-refiners-suspend-october-fuel-exports-bolster-stocks-sources-say
2. Bloomberg, Oct 1, 2026: https://www.rigzone.com/news/wire/china_fuel_exporters_cancel_some_cargoes-01-oct-2026-184744-article/
3. Bloomberg via Oilprice, Sept 16, 2026: https://finance.yahoo.com/energy/articles/china-could-curb-fuel-exports-054500805.html
4. IEA Oil Market Report, Sept 2026: https://www.iea.org/reports/oil-market-report-september-2026 (summary: https://www.ogj.com/general-interest/economics-markets/news/55404447/iea-sees-oil-demand-decline-deepening-as-middle-east-disruptions-persist)
5. EIA Short-Term Energy Outlook, Sept 9, 2026: https://www.eia.gov/outlooks/steo/
6. Moscow Times, Sept 30, 2026: https://www.themoscowtimes.com/2026/09/30/government-extends-diesel-export-ban-until-end-of-october-a93823
7. EIA, uses of diesel: https://www.eia.gov/energyexplained/diesel-fuel/use-of-diesel.php
8. GasBuddy, Sept 4, 2026: https://www.gasbuddy.com/newsroom/pressrelease/2026/09/04/1174
9. USAFacts (EIA data), Sept 21, 2026: https://usafacts.org/articles/diesel-prices-rose-89-from-january-low-to-september-record-high/

## Credits and licences

- Map: Natural Earth 1:50m (public domain), via world-atlas.
- Fonts: Instrument Sans and Source Serif 4 (SIL Open Font License).
- Music and sound effects: composed in code for this video (`ton/partitur.py`).
