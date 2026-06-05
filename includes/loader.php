<div class="loader-container" style="display:flex;justify-content:center;align-items:center;padding:3rem 0;flex-direction:column;gap:0.75rem;width:100%;">
    <div class="spinner" style="width:48px;height:48px;border:4px solid #f1f5f9;border-top-color:var(--accent-blue);border-radius:50%;animation:spin 1s infinite linear;"></div>
    <p style="color:var(--text-secondary);font-weight:600;font-size:1.1rem;margin:0;"><?= htmlspecialchars($loaderText ?? 'Loading...') ?></p>
    <div style="font-size: 13px; color: #1877F2; font-weight: 600; display: flex; align-items: center; gap: 6px; opacity: 0.85;">
        <span style="display: inline-block; width: 6px; height: 6px; background-color: #1877F2; border-radius: 50%;"></span>
        Fetching details from Meta...
    </div>
</div>
