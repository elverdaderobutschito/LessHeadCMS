/*
 * LessHeadCMS - headless CMS generator (PlantUML -> SQLite -> REST API)
 * Copyright (C) 2026 Udo Butschinek
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

// Web worker of frontend/src/components/patternCheck.js (copied unchanged to assets/, hence plain script without imports): tests one value against the regex of a pattern ({ id, regex, value } -> { id, ok }), ok
// null if JavaScript cannot compile the regex after all. Runs apart from the page, so that a regex with catastrophic
// backtracking can be stopped (patternCheck.js terminates the worker after its time limit) instead of freezing the tab.
self.onmessage = (e) => {
  const { id, regex, value } = e.data;
  let ok = null;
  try {
    ok = new RegExp(`^(?:${regex})$`, 'u').test(value);
  } catch (err) {
    ok = null;
  }
  self.postMessage({ id, ok });
};
self.postMessage({ ready: true });
