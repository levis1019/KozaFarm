# Quick Why · Google put AI chips in space. Genius or insane?

A 38.7-second vertical Short (1080×1920) that loops seamlessly. Built with the
[motion-design-skill](https://github.com/Ismyp/motion-design-skill) toolchain (MIT).

- `quick-why-space-data-centers.mp4`: the finished Short (H.264/AAC, −14 LUFS).
- `skript.txt`: the approved voiceover script, one line per beat (question loop: the last line leads into the first).
- `film/film.html`: one 1080×1920 canvas. `window.FILM.seek(t)` is a pure function of t, and `frame(DUR) == frame(0)`, so the picture loops exactly. The satellite is a generic 3D line model, no logos.
- `zeiten.py`: derives the key times from `out/vo.json`.
- `ton/partitur.py`: music and effects on the key times. The beat divides the loop and every tail wraps into the start.
- `out/vo.flac`, `out/vo.json`: the recorded voice (stock synthetic voice "Achird") and its word times.

## Sources (one per factual line)

1. NPR, Oct 1, 2026: https://www.npr.org/2026/10/01/nx-s1-5983697/project-suncatcher-google-ai-data-center-space
2. Google, Oct 1 and Sept 24, 2026: https://blog.google/innovation-and-ai/models-and-research/google-research/project-suncatcher-prototype/ · https://blog.google/innovation-and-ai/models-and-research/google-research/google-project-suncatcher-facts/
3. CNBC, Oct 1, 2026: https://www.cnbc.com/2026/10/01/spacex-to-launch-google-ai-chips-to-orbit-with-planet-labs-satellites.html
4. Scientific American, Oct 1, 2026: https://www.scientificamerican.com/article/google-tests-plan-for-ai-data-centers-in-space-project-suncatcher/
5. CNN, Oct 1, 2026: https://www.cnn.com/2026/10/01/science/google-ai-data-center-satellites-space
6. CNBC, Dec 10, 2025: https://www.cnbc.com/2025/12/10/nvidia-backed-starcloud-trains-first-ai-model-in-space-orbital-data-centers.html
7. Starcloud: https://www.starcloud.com/starcloud-1

## Credits and licences

- Fonts: Instrument Sans (SIL Open Font License).
- Music and sound effects: composed in code for this video.
