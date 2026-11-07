# Field app end-to-end test

`run.sh` runs the whole offline flow in headless Chromium against the real PoD package code: Laravel through Orchestra Testbench, with SQLite. Fleetbase core and the Fleet-Ops login are replaced by the stand-ins in `../Integration/Stubs` and in `server.php`.

The test plays two phones at one distribution point. Phone 1:
1. Signs in; the wrong password is refused.
2. Downloads the distribution, and the second phone does too.
3. Goes offline and reloads. The app must load from its service worker.
4. Hands over to three farmers:
   - a typed card code with an ID check, including a pesticide, so the safety briefing is required (identity level L3);
   - a card scanned with a fake camera showing a real card QR, with a partial collection (L2);
   - a farmer without a card, with a witness (L1).
5. Refuses a replaced card and a forged code.
6. Comes back online and syncs by itself. The server must then hold 3 handovers with the right identity levels, and all 7 photos and signatures, each verified against its hash.

Phone 2, also offline, hands over to the same farmer again. When it syncs, the record becomes a conflict for supervisor review; nothing is lost and nothing is double-counted.

Run it:

```bash
POD_TEST_VENDOR=/path/to/vendor ./run.sh
```

The vendor folder needs the packages listed under Tests in the package README.
