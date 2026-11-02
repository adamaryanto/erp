<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Test Display 7-Seg</title>
<style>
:root{
  --on:#ff2d2d;
  --off:#2b0707;
  --glow:rgba(255,45,45,.55);
  --panel:#0b0d10;
  --line:#252a31;
  --text:#e7edf5;
  --muted:#8b96a5;
}
*{box-sizing:border-box}
html,body{height:100%}
body{
  margin:0;
  background:radial-gradient(1200px 700px at 50% 20%,#1b2129 0%,#0c0f13 45%,#050608 100%);
  color:var(--text);
  font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
  overflow:hidden;
  user-select:none;
}
.stage{
  min-height:100vh;
  display:grid;
  place-items:center;
  padding:4vh 4vw;
}
.indicator{
  position:relative;
  display:flex;
  flex-direction:column;
  align-items:center;
  gap:clamp(10px,2.2vh,24px);
  padding:clamp(18px,3.4vh,48px) clamp(26px,5vw,84px) clamp(14px,2.6vh,34px);
  border-radius:clamp(12px,2vh,26px);
  background:linear-gradient(180deg,#15181c 0%,#080a0c 58%,#030405 100%);
  border:1px solid #2c3138;
  box-shadow:
    0 0 0 6px #0a0c0f,
    0 0 0 7px #232830,
    0 26px 70px rgba(0,0,0,.75),
    inset 0 1px 0 rgba(255,255,255,.06);
}
.indicator::before{
  content:"";
  position:absolute;
  inset:8px;
  border-radius:clamp(8px,1.4vh,18px);
  border:1px solid rgba(255,255,255,.035);
  pointer-events:none;
}
.display{
  display:flex;
  align-items:flex-end;
  justify-content:center;
  gap:clamp(4px,1.1vw,16px);
  line-height:0;
  min-height:clamp(110px,30vh,290px);
}
.digit{
  height:clamp(110px,30vh,290px);
  width:auto;
  overflow:visible;
}
.digit polygon{
  fill:var(--off);
  transition:fill .06s linear;
}
.digit polygon.on{
  fill:var(--on);
  filter:drop-shadow(0 0 0.075em var(--glow));
}
.dot{
  height:clamp(110px,30vh,290px);
  width:auto;
  overflow:visible;
}
.dot circle{
  fill:var(--off);
}
.dot circle.on{
  fill:var(--on);
  filter:drop-shadow(0 0 0.075em var(--glow));
}
.unit{
  font-size:clamp(18px,4vh,46px);
  font-weight:700;
  letter-spacing:.03em;
  color:var(--on);
  text-shadow:0 0 0.35em var(--glow);
  margin:0 0 .35em .2em;
  line-height:1;
}
.statusrow{
  display:flex;
  align-items:center;
  gap:clamp(10px,2.2vw,26px);
  min-height:1.6em;
}
.led{
  display:inline-flex;
  align-items:center;
  gap:.45em;
  font-size:clamp(9px,1.35vh,13px);
  letter-spacing:.14em;
  color:#5c6673;
  text-transform:uppercase;
}
.led::before{
  content:"";
  width:.7em;
  height:.7em;
  border-radius:50%;
  background:#1b222b;
  box-shadow:inset 0 0 0 1px #2b333e;
}
.led.active{color:#d8f7df}
.led.active::before{
  background:#41f07a;
  box-shadow:0 0 0.6em rgba(65,240,122,.8);
}
.led.zero.active{color:#ffe6b0}
.led.zero.active::before{
  background:#ffc247;
  box-shadow:0 0 0.6em rgba(255,194,71,.8);
}
.panel{
  position:fixed;
  top:16px;
  left:16px;
  width:min(360px,calc(100vw - 32px));
  max-height:calc(100vh - 32px);
  overflow:auto;
  padding:14px 16px 16px;
  border-radius:14px;
  background:rgba(11,13,16,.94);
  border:1px solid var(--line);
  box-shadow:0 18px 50px rgba(0,0,0,.55);
  backdrop-filter:blur(8px);
  font-size:13px;
  z-index:20;
}
.panel.hidden{display:none}
.panelhead{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  margin-bottom:12px;
}
.panelhead strong{font-size:13px;letter-spacing:.08em;text-transform:uppercase}
.panelhead button{
  width:30px;
  height:30px;
  border-radius:8px;
  border:1px solid var(--line);
  background:#12161b;
  color:var(--muted);
  cursor:pointer;
}
.panelhead button:hover{color:#fff;border-color:#3a424e}
.row{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:10px}
.row.full{grid-template-columns:1fr}
label.field{display:flex;flex-direction:column;gap:5px;color:var(--muted);font-size:11px;letter-spacing:.06em;text-transform:uppercase}
input[type=number],select{
  width:100%;
  padding:8px 10px;
  border-radius:8px;
  border:1px solid var(--line);
  background:#0f1318;
  color:var(--text);
  font:inherit;
  outline:none;
}
input[type=number]:focus,select:focus{border-color:#3d6bff}
input[type=range]{width:100%;accent-color:var(--on)}
.check{display:flex;align-items:center;gap:8px;color:var(--muted);font-size:12px;margin:8px 0}
.check input{accent-color:var(--on);width:16px;height:16px}
.buttons{display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin-top:10px}
.buttons.two{grid-template-columns:repeat(2,1fr)}
.buttons button{
  padding:9px 6px;
  border-radius:8px;
  border:1px solid var(--line);
  background:#12161b;
  color:var(--text);
  font-size:12px;
  cursor:pointer;
  font-family:inherit;
}
.buttons button:hover{background:#1a2027;border-color:#3a424e}
.buttons button.primary{background:#1d3f2a;border-color:#2c6b45;color:#d8ffe6}
.buttons button.danger{background:#3a1418;border-color:#6b2c36;color:#ffdfe3}
.hint{color:var(--muted);font-size:11px;line-height:1.5;margin-top:10px}
.showpanel{
  position:fixed;
  top:16px;
  left:16px;
  z-index:19;
  display:none;
  padding:10px 14px;
  border-radius:10px;
  border:1px solid var(--line);
  background:rgba(11,13,16,.9);
  color:var(--text);
  font:inherit;
  font-size:12px;
  cursor:pointer;
}
body.panel-hidden .panel{display:none}
body.panel-hidden .showpanel{display:block}
@media (min-width:1250px){
  body:not(.panel-hidden) .stage{padding-left:390px}
}
@media (max-width:640px){
  .indicator{padding:16px 18px 12px}
  .display{gap:6px}
  .digit,.dot{height:clamp(84px,26vh,170px)}
  .unit{font-size:22px}
  .panel{top:8px;left:8px;width:calc(100vw - 16px);max-height:calc(100vh - 16px)}
}
</style>
</head>
<body>
<div class="stage">
  <div class="indicator" aria-label="Display timbangan tujuh segmen">
    <div class="display" id="display" aria-live="polite"></div>
    <div class="statusrow">
      <span class="led" id="ledStable">Stable</span>
      <span class="led zero" id="ledZero">Zero</span>
      <span class="led active" id="ledNet">Net</span>
      <span class="led active" id="ledPower">Power</span>
    </div>
  </div>
</div>

<aside class="panel" id="panel">
  <div class="panelhead">
    <strong>Test Display BX1</strong>
    <button type="button" id="hidePanel" title="Sembunyikan panel (H)">X</button>
  </div>

  <div class="row full">
    <label class="field">Berat (kg)
      <input type="number" id="weight" min="0" max="999" step="0.01" value="20.50">
    </label>
  </div>
  <input type="range" id="slider" min="0" max="200" step="0.01" value="20.50">

  <div class="row">
    <label class="field">Digit
      <select id="digits">
        <option value="4">4 digit</option>
        <option value="5">5 digit</option>
        <option value="6" selected>6 digit (BX1)</option>
      </select>
    </label>
    <label class="field">Desimal
      <select id="decimals">
        <option value="2" selected>2</option>
        <option value="3">3</option>
        <option value="1">1</option>
        <option value="0">0</option>
      </select>
    </label>
  </div>

  <div class="row">
    <label class="field">Warna
      <select id="color">
        <option value="red" selected>Merah (BX1)</option>
        <option value="green">Hijau (web)</option>
        <option value="amber">Amber</option>
      </select>
    </label>
    <label class="field">Preset
      <select id="preset">
        <option value="bx1" selected>BX1 6 digit</option>
        <option value="web">Web 5 digit</option>
        <option value="three">BX1 3 desimal</option>
      </select>
    </label>
  </div>

  <label class="check"><input type="checkbox" id="leading" checked> Nol depan (020.50)</label>
  <label class="check"><input type="checkbox" id="jitter"> Jitter berat (+/- 0.01)</label>

  <div class="buttons">
    <button type="button" data-add="-1">-1</button>
    <button type="button" data-add="-0.1">-0.1</button>
    <button type="button" data-add="-0.01">-0.01</button>
    <button type="button" data-add="0.01">+0.01</button>
    <button type="button" data-add="0.1">+0.1</button>
    <button type="button" data-add="1">+1</button>
    <button type="button" id="zero">0</button>
    <button type="button" id="random">Random</button>
  </div>

  <div class="buttons two">
    <button type="button" class="primary" id="fullscreen">Fullscreen</button>
    <button type="button" class="danger" id="reset">Reset</button>
  </div>

  <div class="hint">H = panel, F = fullscreen, 0 = nol, R = random, J = jitter, panah atas/bawah = +/- 0.01.</div>
</aside>

<button type="button" class="showpanel" id="showPanel">Panel</button>

<script>
(function () {
  const SEGMENTS = ["a", "b", "c", "d", "e", "f", "g"];
  const DIGIT_MAP = {
    "0": "abcdef",
    "1": "bc",
    "2": "abdeg",
    "3": "abcdg",
    "4": "bcfg",
    "5": "acdfg",
    "6": "acdefg",
    "7": "abc",
    "8": "abcdefg",
    "9": "abcdfg"
  };
  const SEG_POINTS = {
    a: "12,7 58,7 51,15 19,15",
    b: "59,10 59,57 51,50 51,18",
    c: "59,63 59,110 51,102 51,70",
    d: "12,113 19,105 51,105 58,113",
    e: "11,63 19,70 19,102 11,110",
    f: "11,10 19,18 19,50 11,57",
    g: "12,60 19,53 51,53 58,60 51,67 19,67"
  };
  const PALETTES = {
    red: { on: "#ff2d2d", off: "#2b0707", glow: "rgba(255,45,45,.55)" },
    green: { on: "#41f07a", off: "#082315", glow: "rgba(65,240,122,.5)" },
    amber: { on: "#ffb020", off: "#2a1a03", glow: "rgba(255,176,32,.55)" }
  };
  const PRESETS = {
    bx1: { digits: 6, decimals: 2, leading: true, color: "red" },
    web: { digits: 5, decimals: 2, leading: false, color: "green" },
    three: { digits: 6, decimals: 3, leading: true, color: "red" }
  };

  const state = {
    weight: 20.5,
    digits: 6,
    decimals: 2,
    leading: true,
    color: "red",
    jitter: false
  };

  const display = document.getElementById("display");
  const weightInput = document.getElementById("weight");
  const slider = document.getElementById("slider");
  const digitsSelect = document.getElementById("digits");
  const decimalsSelect = document.getElementById("decimals");
  const colorSelect = document.getElementById("color");
  const presetSelect = document.getElementById("preset");
  const leadingInput = document.getElementById("leading");
  const jitterInput = document.getElementById("jitter");
  const ledStable = document.getElementById("ledStable");
  const ledZero = document.getElementById("ledZero");
  const panel = document.getElementById("panel");
  let jitterTimer = null;

  function clampWeight(value) {
    const n = Number(value);
    if (!Number.isFinite(n)) return 0;
    return Math.min(999.999, Math.max(0, n));
  }

  function rounded(value) {
    return Math.round(value * 1000) / 1000;
  }

  function applyPalette() {
    const palette = PALETTES[state.color] || PALETTES.red;
    const root = document.documentElement.style;
    root.setProperty("--on", palette.on);
    root.setProperty("--off", palette.off);
    root.setProperty("--glow", palette.glow);
  }

  function makeDigit(character) {
    const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
    svg.setAttribute("viewBox", "0 0 70 120");
    svg.setAttribute("class", "digit");
    svg.setAttribute("aria-hidden", "true");
    const active = DIGIT_MAP[character] || "";
    SEGMENTS.forEach(function (segment) {
      const polygon = document.createElementNS("http://www.w3.org/2000/svg", "polygon");
      polygon.setAttribute("points", SEG_POINTS[segment]);
      if (active.indexOf(segment) !== -1) polygon.classList.add("on");
      svg.appendChild(polygon);
    });
    return svg;
  }

  function makeDot(active) {
    const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
    svg.setAttribute("viewBox", "0 0 26 120");
    svg.setAttribute("class", "dot");
    svg.setAttribute("aria-hidden", "true");
    const circle = document.createElementNS("http://www.w3.org/2000/svg", "circle");
    circle.setAttribute("cx", "13");
    circle.setAttribute("cy", "108");
    circle.setAttribute("r", "6.5");
    if (active) circle.classList.add("on");
    svg.appendChild(circle);
    return svg;
  }

  function formatParts() {
    const fixed = state.weight.toFixed(state.decimals);
    const pieces = fixed.split(".");
    let integerPart = pieces[0];
    const fractionPart = pieces[1] || "";
    if (state.leading) {
      const needed = Math.max(0, state.digits - fractionPart.length);
      integerPart = integerPart.padStart(needed, "0");
    }
    return { integerPart: integerPart, fractionPart: fractionPart };
  }

  function render() {
    const parts = formatParts();
    const nodes = [];
    parts.integerPart.split("").forEach(function (character) {
      nodes.push(makeDigit(character));
    });
    if (state.decimals > 0) {
      nodes.push(makeDot(true));
      parts.fractionPart.split("").forEach(function (character) {
        nodes.push(makeDigit(character));
      });
    }
    const unit = document.createElement("span");
    unit.className = "unit";
    unit.textContent = "kg";
    nodes.push(unit);
    display.replaceChildren.apply(display, nodes);

    ledStable.classList.toggle("active", !state.jitter);
    ledZero.classList.toggle("active", state.weight < 0.005);
    weightInput.value = state.weight.toFixed(2);
    slider.value = String(state.weight);
  }

  function setWeight(value) {
    state.weight = rounded(clampWeight(value));
    render();
  }

  function syncControls() {
    digitsSelect.value = String(state.digits);
    decimalsSelect.value = String(state.decimals);
    colorSelect.value = state.color;
    leadingInput.checked = state.leading;
    jitterInput.checked = state.jitter;
  }

  function applyPreset(name) {
    const preset = PRESETS[name];
    if (!preset) return;
    state.digits = preset.digits;
    state.decimals = preset.decimals;
    state.leading = preset.leading;
    state.color = preset.color;
    applyPalette();
    syncControls();
    render();
  }

  function syncJitter() {
    if (jitterTimer) {
      clearInterval(jitterTimer);
      jitterTimer = null;
    }
    if (state.jitter) {
      jitterTimer = setInterval(function () {
        const delta = (Math.random() * 0.02) - 0.01;
        setWeight(state.weight + delta);
      }, 650);
    }
    ledStable.classList.toggle("active", !state.jitter);
  }

  function togglePanel(force) {
    const hide = typeof force === "boolean" ? force : !document.body.classList.contains("panel-hidden");
    document.body.classList.toggle("panel-hidden", hide);
  }

  function requestFullscreen() {
    const target = document.documentElement;
    if (document.fullscreenElement) {
      document.exitFullscreen().catch(function () {});
      return;
    }
    if (target.requestFullscreen) target.requestFullscreen().catch(function () {});
  }

  function randomWeight() {
    setWeight(5 + Math.random() * 95);
  }

  weightInput.addEventListener("input", function () {
    setWeight(weightInput.value);
  });
  slider.addEventListener("input", function () {
    setWeight(slider.value);
  });

  digitsSelect.addEventListener("change", function () {
    state.digits = Number(digitsSelect.value);
    render();
  });
  decimalsSelect.addEventListener("change", function () {
    state.decimals = Number(decimalsSelect.value);
    render();
  });
  colorSelect.addEventListener("change", function () {
    state.color = colorSelect.value;
    applyPalette();
  });
  presetSelect.addEventListener("change", function () {
    applyPreset(presetSelect.value);
  });
  leadingInput.addEventListener("change", function () {
    state.leading = leadingInput.checked;
    render();
  });
  jitterInput.addEventListener("change", function () {
    state.jitter = jitterInput.checked;
    syncJitter();
  });

  document.querySelectorAll("[data-add]").forEach(function (button) {
    button.addEventListener("click", function () {
      setWeight(state.weight + Number(button.dataset.add));
    });
  });

  document.getElementById("zero").addEventListener("click", function () {
    setWeight(0);
  });
  document.getElementById("random").addEventListener("click", randomWeight);
  document.getElementById("fullscreen").addEventListener("click", requestFullscreen);
  document.getElementById("reset").addEventListener("click", function () {
    setWeight(20.5);
    state.jitter = false;
    jitterInput.checked = false;
    syncJitter();
    applyPreset("bx1");
    presetSelect.value = "bx1";
  });
  document.getElementById("hidePanel").addEventListener("click", function () {
    togglePanel(true);
  });
  document.getElementById("showPanel").addEventListener("click", function () {
    togglePanel(false);
  });

  document.addEventListener("keydown", function (event) {
    if (event.target && ["INPUT", "SELECT", "TEXTAREA"].indexOf(event.target.tagName) !== -1) return;
    const key = event.key.toLowerCase();
    if (key === "h") {
      event.preventDefault();
      togglePanel();
    } else if (key === "f") {
      event.preventDefault();
      requestFullscreen();
    } else if (key === "0") {
      event.preventDefault();
      setWeight(0);
    } else if (key === "r") {
      event.preventDefault();
      randomWeight();
    } else if (key === "j") {
      event.preventDefault();
      state.jitter = !state.jitter;
      jitterInput.checked = state.jitter;
      syncJitter();
    } else if (event.key === "ArrowUp") {
      event.preventDefault();
      setWeight(state.weight + 0.01);
    } else if (event.key === "ArrowDown") {
      event.preventDefault();
      setWeight(state.weight - 0.01);
    } else if (event.key === "PageUp") {
      event.preventDefault();
      setWeight(state.weight + 0.1);
    } else if (event.key === "PageDown") {
      event.preventDefault();
      setWeight(state.weight - 0.1);
    }
  });

  document.body.classList.add("panel-hidden");
  applyPalette();
  syncControls();
  render();
})();
</script>
</body>
</html>
