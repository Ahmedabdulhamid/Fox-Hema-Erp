<style>
    .fox-product-import-page .fi-page-header-main-ctn { gap: 0; }
    .fox-import { --import-ink: #0f1d36; --import-muted: #475b7b; --import-green: #008c74; color: var(--import-ink); background: radial-gradient(ellipse at 78% 0%, #e3f3f5 0, transparent 40%), linear-gradient(155deg, #f0faf9 0, #f7f9fc 28%, #f5f8fc 70%); border-radius: 16px; padding: 4px 0 0; min-width: 0; }
    .fox-import *, .fox-import *::before, .fox-import *::after { box-sizing: border-box; }
    .fox-import [x-cloak] { display: none !important; }
    .fox-import svg { width: 22px; height: 22px; flex-shrink: 0; }
    .fox-import .fox-import__excel { width: 44px; height: 49px; }
    .fox-import__header { display: flex; align-items: center; justify-content: space-between; gap: 24px; padding: 8px 0 24px; }
    .fox-import__heading { display: flex; align-items: center; gap: 18px; min-width: 0; }
    .fox-import h1 { margin: 0 0 5px; font-size: clamp(23px, 2.1vw, 29px); font-weight: 800; line-height: 1.4; letter-spacing: -.6px; }
    .fox-import__subtitle { margin: 0; color: var(--import-muted); font-size: 14px; line-height: 1.7; }
    .fox-import__button { display: inline-flex; align-items: center; justify-content: center; gap: 10px; border: 1px solid #b6c6d6; border-radius: 10px; padding: 8px 17px; background: #fff; color: var(--import-ink); font-size: 14px; font-weight: 750; line-height: 1.5; text-decoration: none; cursor: pointer; transition: background .15s, box-shadow .15s; }
    .fox-import__button:hover { background: #f1f7f8; box-shadow: 0 2px 6px #13244112; }
    .fox-import__button:focus-visible, .fox-import__icon-button:focus-visible, .fox-import__dropzone:focus-within, .fox-import summary:focus-visible { outline: 3px solid #56c9b8; outline-offset: 3px; }
    .fox-import__button:disabled, .fox-import button:disabled { opacity: .55; cursor: wait; }
    .fox-import__button--back { white-space: nowrap; box-shadow: 0 2px 5px #13244116; border-color: #dbe7eb; border-radius: 14px; }
    .fox-import__button--primary { color: #fff; border-color: #008b72; background: linear-gradient(105deg, #00866d, #009b7e); box-shadow: 0 2px 4px #00866d20; min-width: 218px; }
    .fox-import__button--primary:hover { background: #007d67; }
    .fox-import__button--outline { border-color: #197e7e; width: 100%; padding-block: 6px; }
    .fox-import__stepper { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); align-items: center; gap: clamp(15px, 6vw, 80px); list-style: none; margin: 0 4% 24px; padding: 0; position: relative; isolation: isolate; }
    .fox-import__stepper::before { content: ''; position: absolute; inset-inline: 10%; top: 50%; height: 3px; background: #b9c8d7; z-index: -1; }
    .fox-import__step { display: flex; justify-content: center; align-items: center; gap: 11px; min-height: 49px; border-radius: 30px; background: linear-gradient(#edf2f9, #dce4ef); box-shadow: 0 2px 3px #15243d20; padding: 10px 12px; font-size: 14px; font-weight: 750; }
    .fox-import__step[aria-current="step"], .fox-import__step--done { background: linear-gradient(100deg, #008e77, #009b7c); color: white; }
    .fox-import__step[aria-current="step"] svg { background: #fff; color: #009b7c; border-radius: 50%; padding: 5px; width: 33px; height: 33px; }
    .fox-import__grid { display: grid; grid-template-columns: minmax(0, 2.16fr) minmax(260px, 1fr); gap: 18px; align-items: start; }
    .fox-import__main, .fox-import__aside { display: grid; gap: 12px; min-width: 0; }
    .fox-import__card { background: #ffffffed; border: 1px solid #e0e8f0; box-shadow: 0 2px 5px #182c4810; border-radius: 16px; padding: 16px 23px 12px; min-width: 0; }
    .fox-import__card-heading { display: flex; align-items: center; gap: 14px; margin-block-end: 12px; }
    .fox-import__card-heading > div:last-child { flex: 1; min-width: 0; }
    .fox-import__card h2, .fox-import__card h3 { margin: 0; font-size: 18px; font-weight: 800; line-height: 1.6; }
    .fox-import__card p { margin: 2px 0 0; color: var(--import-muted); font-size: 13px; line-height: 1.65; }
    .fox-import__title-row { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
    .fox-import__tile { display: flex; justify-content: center; align-items: center; width: 56px; height: 52px; flex-shrink: 0; border-radius: 15px; background: #e7f6f2; color: #009681; }
    .fox-import__tile .fox-import__excel { width: 35px; height: 39px; }
    .fox-import__tile > svg:not(.fox-import__excel) { width: 32px; height: 32px; }
    .fox-import__tile--blue { background: #e8f1ff; color: #1675ff; }
    .fox-import__badge { display: inline-flex; align-items: center; border-radius: 20px; padding: 2px 13px; font-size: 13px; font-weight: 700; background: #ffe6eb; color: #e11d48; }
    .fox-import__badge--optional { color: #1765e8; background: #e8f1ff; }
    .fox-import__dropzone { display: flex; position: relative; flex-direction: column; align-items: center; justify-content: center; gap: 3px; min-height: 125px; padding: 10px 16px; border: 1.5px dashed #13a98c; border-radius: 11px; background: linear-gradient(110deg, #eefaf7, #e8f8f3); text-align: center; cursor: pointer; }
    .fox-import__dropzone > svg { color: #009a83; width: 40px; height: 35px; margin-block-end: 1px; }
    .fox-import__dropzone strong { font-size: 15px; font-weight: 750; line-height: 1.4; }
    .fox-import__dropzone > span { font-size: 12px; line-height: 1.35; }
    .fox-import__dropzone--images { min-height: 110px; gap: 2px; border-color: #438bff; background: linear-gradient(105deg, #f1f7ff, #e7f1ff); }
    .fox-import__dropzone--images > svg { color: #1675ff; width: 32px; height: 26px; }
    .fox-import__dropzone.is-dragging { outline: 3px solid #45c7b3; background: #d9f5ed; }
    .fox-import__dropzone--images.is-dragging { outline-color: #78a9ff; background: #dfeeff; }
    .fox-import__file-input { position: absolute; width: 1px; height: 1px; padding: 0; border: 0; opacity: 0; overflow: hidden; }
    .fox-import__limits { display: flex; align-items: center; justify-content: center; flex-wrap: wrap; gap: 6px; margin-block-start: 3px; font-size: 11px; }
    .fox-import__chip { padding: 2px 12px; border-radius: 20px; background: #ccefe8; font-size: 11px; font-weight: 650; }
    .fox-import__dropzone--images .fox-import__chip { background: #d3e7ff; }
    .fox-import__selected-file { display: flex; align-items: center; gap: 10px; border: 1px solid #dce4ed; border-radius: 11px; background: #f8fafde0; padding: 5px 10px; margin-block-start: 9px; min-width: 0; }
    .fox-import__selected-file .fox-import__excel { width: 28px; height: 31px; }
    .fox-import__file-name { flex: 1; min-width: 0; text-align: start; }
    .fox-import__file-name b { display: block; font-size: 12px; overflow-wrap: anywhere; }
    .fox-import__file-name small { display: block; color: var(--import-muted); font-size: 11px; }
    .fox-import__success { color: #00886f; width: 19px !important; height: 19px !important; }
    .fox-import__icon-button { border: 1px solid #d0dae5; border-radius: 50%; display: inline-flex; justify-content: center; align-items: center; width: 28px; height: 28px; color: var(--import-ink); background: #fff; cursor: pointer; flex-shrink: 0; }
    .fox-import__icon-button svg { width: 18px; height: 18px; }
    .fox-import__image-row { display: flex; align-items: center; justify-content: space-between; gap: 15px; margin-block-start: 10px; }
    .fox-import__image-count { display: flex; align-items: center; gap: 6px; font-size: 12px; white-space: nowrap; }
    .fox-import__image-count svg { width: 17px; height: 17px; }
    .fox-import__thumbnails { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; min-width: 0; }
    .fox-import__thumbnail { position: relative; width: 49px; height: 39px; border: 1px solid #dce4ed; border-radius: 7px; background: #f8fafc; box-shadow: 0 1px 2px #15243d15; }
    .fox-import__thumbnail img { width: 100%; height: 100%; object-fit: contain; border-radius: inherit; }
    .fox-import__thumbnail > .fox-import__icon-button { position: absolute; top: -8px; inset-inline-end: -6px; width: 19px; height: 19px; z-index: 1; }
    .fox-import__thumbnail > .fox-import__icon-button svg { width: 13px; height: 13px; }
    .fox-import__add { display: flex; align-items: center; justify-content: center; width: 44px; height: 36px; border: 1px solid #d4deea; border-radius: 7px; background: #f7fafc; cursor: pointer; font-size: 12px; }
    .fox-import__aside .fox-import__card { padding: 14px 20px 12px; }
    .fox-import__aside .fox-import__card-heading { margin-block-end: 11px; }
    .fox-import__aside .fox-import__card h2 { font-size: 17px; }
    .fox-import__aside .fox-import__card p { font-size: 12px; line-height: 1.4; }
    .fox-import__guidance-heading { display: flex; align-items: center; gap: 12px; margin-block-end: 10px; }
    .fox-import__guidance-heading svg { background: #00a99b; color: white; border-radius: 50%; width: 25px; height: 25px; padding: 3px; }
    .fox-import__guide { border: 1px solid #e4eaf2; border-radius: 11px; background: linear-gradient(#fff, #f7f9fc); margin-block-start: 4px; }
    .fox-import__guide summary { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 7px 12px; font-size: 13px; font-weight: 650; cursor: pointer; list-style: none; }
    .fox-import__guide summary::-webkit-details-marker, .fox-import__history > summary::-webkit-details-marker { display: none; }
    .fox-import__guide summary svg { width: 14px; height: 14px; transition: transform .15s; }
    .fox-import__guide[open] summary svg { transform: rotate(-90deg); }
    .fox-import__guide p { padding: 2px 12px 12px; overflow-wrap: anywhere; }
    .fox-import__footer { display: flex; align-items: center; justify-content: space-between; gap: 15px; padding: 12px 3px; margin-block-start: 8px; border-block-start: 1px solid #e0e8ef; background: #ffffffb5; }
    .fox-import__error { color: #be123c !important; font-size: 13px; margin-block-start: 6px !important; overflow-wrap: anywhere; }
    .fox-import__notice { padding: 10px 13px; margin-block: 12px; border: 1px solid #dce9e7; border-radius: 10px; background: #f0faf6; font-size: 13px; }
    .fox-import__actions { display: flex; gap: 9px; flex-wrap: wrap; margin-block: 16px; }
    .fox-import__table-wrap { overflow-x: auto; border: 1px solid #e3eaf0; border-radius: 11px; margin-block: 16px; }
    .fox-import__table { border-collapse: collapse; width: 100%; text-align: start; font-size: 13px; }
    .fox-import__table th { padding: 11px 12px; background: #f0f6f8; font-weight: 700; text-align: start; }
    .fox-import__table td { padding: 12px; border-block-start: 1px solid #e5ebf2; vertical-align: top; }
    .fox-import__table td:first-child { min-width: 160px; }
    .fox-import__table a { color: #007f70; text-decoration: underline; }
    .fox-import__table p { font-size: 12px; }
    .fox-import__progress { width: 100%; height: 10px; border-radius: 10px; accent-color: #009b7e; margin-block: 12px; }
    .fox-import__history { margin-block-start: 20px; border: 1px solid #e0e8f0; border-radius: 14px; background: #fff; padding: 14px 20px; box-shadow: 0 2px 4px #13244108; }
    .fox-import__history > summary { display: flex; align-items: center; gap: 10px; font-weight: 750; font-size: 15px; cursor: pointer; list-style: none; }
    .fox-import__history-item { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; padding: 12px 0; border-block-end: 1px solid #e5ebf2; font-size: 12px; }
    .fox-import__history-item button { text-align: start; cursor: pointer; color: #007c70; }
    .fox-import__history-item time { display: block; color: var(--import-muted); }
    .fox-import__empty { color: var(--import-muted); padding-block: 15px; font-size: 13px; }
    .fox-import [dir="ltr"] { unicode-bidi: isolate; }
    @media (max-width: 1050px) {
        .fox-import__grid { grid-template-columns: minmax(0, 1.8fr) minmax(230px, 1fr); gap: 14px; }
        .fox-import__card { padding-inline: 17px; }
        .fox-import__stepper { margin-inline: 0; gap: 24px; }
        .fox-import__image-row { align-items: start; flex-direction: column; gap: 9px; }
    }
    @media (max-width: 760px) {
        .fox-import__header { align-items: start; gap: 12px; padding-block-end: 20px; }
        .fox-import__heading { gap: 10px; }
        .fox-import__heading > .fox-import__excel { width: 34px; height: 40px; }
        .fox-import h1 { font-size: 22px; }
        .fox-import__button--back { font-size: 12px; padding: 7px 10px; }
        .fox-import__grid { grid-template-columns: minmax(0, 1fr); }
        .fox-import__stepper { gap: 10px; margin-block-end: 18px; }
        .fox-import__step { flex-direction: column; gap: 5px; min-height: 70px; padding: 9px 6px; font-size: 11px; text-align: center; border-radius: 14px; }
        .fox-import__step svg, .fox-import__step[aria-current="step"] svg { width: 25px; height: 25px; }
        .fox-import__step[aria-current="step"] svg { padding: 3px; }
        .fox-import__aside { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .fox-import__aside > :last-child { grid-column: 1 / -1; }
        .fox-import__aside .fox-import__card { padding: 13px; }
        .fox-import__aside .fox-import__card-heading { align-items: start; gap: 8px; }
        .fox-import__aside .fox-import__card h2 { font-size: 15px; }
        .fox-import__aside .fox-import__tile { width: 36px; height: 38px; border-radius: 9px; }
        .fox-import__aside .fox-import__tile svg { width: 25px; height: 28px; }
        .fox-import__image-row { flex-direction: row; flex-wrap: wrap; }
    }
    @media (max-width: 420px) {
        .fox-import__header { flex-wrap: wrap; }
        .fox-import__button--back { margin-inline-start: auto; }
        .fox-import__card { padding: 14px; }
        .fox-import__card h2 { font-size: 16px; }
        .fox-import__title-row { gap: 5px; }
        .fox-import__card-heading { gap: 9px; }
        .fox-import__badge { font-size: 11px; padding-inline: 9px; }
        .fox-import__footer .fox-import__button { padding-inline: 12px; font-size: 13px; min-width: 0; }
        .fox-import__footer .fox-import__button--primary { flex: 1; }
    }
    @media (prefers-reduced-motion: reduce) { .fox-import *, .fox-import *::before, .fox-import *::after { transition: none !important; } }
    .fox-import__language-links { display: flex; justify-content: center; flex-wrap: wrap; gap: 7px; margin-block-start: 9px; font-size: 12px; color: #087f74; }
    .fox-import__language-links a { text-decoration: underline; text-underline-offset: 3px; }
</style>
