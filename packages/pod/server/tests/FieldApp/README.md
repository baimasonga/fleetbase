# Field app end-to-end test

`run.sh` runs the whole offline flow in headless Chromium against the real PoD package code: Laravel through Orchestra Testbench, with SQLite. Fleetbase core and the Fleet-Ops login are replaced by the stand-ins in `../Integration/Stubs` and in `server.php` (an officer, `officer@example.org`, and a supervisor, `supervisor@example.org`, both with the password `field-pass`).

The test plays one distribution day with two phones and no signal at the distribution point:

1. **Depot:** both phones sign in (a wrong password is refused) and download the day, which is still planned. The app must then load with no network.
2. **Phone 1 opens the day** with a witnessed count. A count higher than the site holds is refused.
3. **Phone 1 serves three farmers:**
   - a typed card code with an ID check, including a pesticide, so the safety briefing is required (identity level L3);
   - a card scanned with a fake camera showing a real card QR, with a partial collection (L2);
   - a farmer without a card, with a witness (L1).

   A replaced card and a forged code are refused.
4. **Phone 2 joins the day** and serves the first farmer again.
5. **Phone 1 closes the day** with the physical count, which is lower than its own records because phone 2 also handed out goods.
6. **Back in signal:**
   - Phone 2 syncs first, and its handover waits for the opening.
   - Phone 1 syncs: the opening and handovers are accepted, and the closing is held as unbalanced.
   - Phone 2's handover becomes a conflict.
   - Phone 1 records the duplicated goods as missing stock, and the closing then goes through by itself.
7. **Review:** the officer can't resolve the conflict on their own record. A supervisor signs in on a third phone, sees the photos, and marks it reviewed (the day is closed, so "missing stock" isn't offered).
8. **The server must end with:**
   - a closed, balanced, sealed day;
   - 3 handovers with the right identity levels;
   - 1 conflict, resolved as reviewed;
   - all 7 handover photos and signatures uploaded and verified against their hashes;
   - 3 discrepancies;
   - the witnesses on record.

Run it:

```bash
POD_TEST_VENDOR=/path/to/vendor ./run.sh
```

The vendor folder needs the packages listed under Tests in the package README.
