<?php

/**
 * Canonical account settings view shared by admin, nutritionist, and parent
 * portals. The page-specific handlers remain responsible for authorization
 * and persistence because staff and parent accounts use different tables.
 *
 * Required variables:
 *   $settingsProfile, $settingsRoleLabel, $settingsUserCode,
 *   $settingsLastLoginText, $settingsEscaper
 *
 * Optional variables:
 *   $settingsShowUsername (bool), $settingsParentType (string),
 *   $settingsParentTypeOptions (string[]), $settingsMustChangeBanner (bool)
 */
$settingsProfile = is_array($settingsProfile ?? null) ? $settingsProfile : [];
$settingsEscaper = is_callable($settingsEscaper ?? null) ? $settingsEscaper : 'admin_e';
$settingsShowUsername = (bool)($settingsShowUsername ?? false);
$settingsParentTypeOptions = $settingsParentTypeOptions ?? ['Father', 'Mother', 'Guardian', 'Grandparent', 'Other'];
$settingsMustChangeBanner = (bool)($settingsMustChangeBanner ?? false);

$settingsFullName = trim((string)($settingsProfile['name'] ?? ''));
$settingsNameParts = $settingsFullName === '' ? [] : preg_split('/\s+/', $settingsFullName);
$settingsLastName = count($settingsNameParts) > 1 ? (string)array_pop($settingsNameParts) : '';
$settingsFirstName = implode(' ', $settingsNameParts);
$settingsAvatarColor = admin_avatar_color($settingsFullName !== '' ? $settingsFullName : (string)($settingsProfile['email'] ?? 'User'));
$settingsAvatarInitials = $settingsFullName !== '' ? admin_initials($settingsFullName) : strtoupper(substr((string)($settingsProfile['email'] ?? 'U'), 0, 1));
$settingsEsc = $settingsEscaper;
?>
<div class="acct-settings" data-account-settings>
    <?php if ($settingsMustChangeBanner): ?>
        <div class="admin-flash is-error" role="alert" style="display:block;">
            <strong>Palitan ang temporary password.</strong>
            Maglagay ng sarili mong password para ma-secure ang account.
        </div>
    <?php endif; ?>

    <section class="acct-hero">
        <span class="acct-hero-avatar" style="background:<?php echo $settingsEsc($settingsAvatarColor); ?>;" aria-hidden="true"><?php echo $settingsEsc($settingsAvatarInitials); ?></span>
        <div class="acct-hero-info">
            <div class="acct-hero-name-row">
                <h2 class="acct-hero-name"><?php echo $settingsEsc($settingsFullName !== '' ? $settingsFullName : 'Unnamed account'); ?></h2>
                <?php if ((string)($settingsUserCode ?? '') !== ''): ?>
                    <span class="acct-id-badge"><?php echo $settingsEsc((string)$settingsUserCode); ?></span>
                <?php endif; ?>
            </div>
            <div class="acct-hero-meta"><span class="acct-role"><?php echo $settingsEsc((string)$settingsRoleLabel); ?></span></div>
            <p class="acct-hero-signin">Last sign-in &middot; <?php echo $settingsEsc((string)$settingsLastLoginText); ?></p>
        </div>
    </section>

    <div class="acct-grid">
        <form method="post" id="account-profile-form" class="acct-col-main" data-account-profile-form data-validate-form>
            <input type="hidden" name="update_account" value="1">
            <div class="admin-flash is-error" data-validate-banner style="display:none;"></div>

            <section class="admin-section acct-card">
                <header class="admin-section-head"><h2 class="admin-section-title">Basic info</h2></header>
                <div class="admin-form-grid acct-form-grid">
                    <label class="admin-field">
                        <span>First name</span>
                        <input id="account_first_name" name="first_name" required data-validate="name" data-label="First name" autocomplete="given-name" value="<?php echo $settingsEsc($settingsFirstName); ?>">
                        <span class="admin-field-message"></span>
                    </label>
                    <label class="admin-field">
                        <span>Last name</span>
                        <input id="account_last_name" name="last_name" required data-validate="name" data-label="Last name" autocomplete="family-name" value="<?php echo $settingsEsc($settingsLastName); ?>">
                        <span class="admin-field-message"></span>
                    </label>
                    <label class="admin-field">
                        <span>Email</span>
                        <input id="account_email" type="email" name="email" required data-validate="email" data-label="Email" autocomplete="email" value="<?php echo $settingsEsc((string)($settingsProfile['email'] ?? '')); ?>">
                        <span class="admin-field-message"></span>
                    </label>
                    <?php if ($settingsShowUsername): ?>
                        <label class="admin-field">
                            <span>Username</span>
                            <input id="account_username" name="username" required data-validate="username" data-label="Username" autocomplete="username" maxlength="30" value="<?php echo $settingsEsc((string)($settingsProfile['username'] ?? '')); ?>">
                            <span class="admin-field-message"></span>
                        </label>
                    <?php endif; ?>
                    <label class="admin-field">
                        <span>Mobile number</span>
                        <input id="account_phone" name="phone" data-validate="phone-ph" data-label="Phone" autocomplete="tel" inputmode="tel" placeholder="09171234567" value="<?php echo $settingsEsc((string)($settingsProfile['phone'] ?? '')); ?>">
                        <span class="admin-field-message"></span>
                    </label>
                    <?php if (array_key_exists('parent_type', $settingsProfile)): ?>
                        <label class="admin-field">
                            <span>Parent type</span>
                            <select id="account_parent_type" name="parent_type" required>
                                <?php foreach ($settingsParentTypeOptions as $type): ?>
                                    <option value="<?php echo $settingsEsc((string)$type); ?>" <?php echo ((string)($settingsProfile['parent_type'] ?? 'Guardian') === (string)$type) ? 'selected' : ''; ?>><?php echo $settingsEsc((string)$type); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="admin-field-message"></span>
                        </label>
                    <?php endif; ?>
                </div>
            </section>

            <section class="admin-section acct-card">
                <header class="admin-section-head"><h2 class="admin-section-title">Address</h2></header>
                <div class="admin-field-wide">
                    <div class="admin-address-picker" data-psgc-picker data-psgc-address-target="account_address">
                        <label class="admin-field"><span class="acct-locked-label">Province <span class="acct-lock" title="Locked" aria-label="Locked"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z"/></svg></span></span><select data-psgc="province" aria-label="Province (locked)" title="Locked" disabled><option value="">Loading...</option></select></label>
                        <label class="admin-field"><span class="acct-locked-label">City / Municipality <span class="acct-lock" title="Locked" aria-label="Locked"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z"/></svg></span></span><select data-psgc="city" aria-label="City or municipality (locked)" title="Locked" disabled><option value="">Loading...</option></select></label>
                        <label class="admin-field">
                            <span>Barangay</span>
                            <div class="acct-combobox" data-account-combobox>
                                <div class="acct-combobox-control">
                                    <input id="account_barangay_input" type="text" class="acct-combobox-input" role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="account_barangay_list" autocomplete="off" placeholder="Search barangay" data-combobox-input>
                                    <button type="button" class="acct-combobox-toggle" tabindex="-1" aria-hidden="true" data-combobox-toggle>
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"/></svg>
                                    </button>
                                </div>
                                <ul id="account_barangay_list" class="acct-combobox-list" role="listbox" data-combobox-list hidden></ul>
                                <select id="account_barangay_select" data-psgc="barangay" class="acct-sr-only" tabindex="-1" aria-hidden="true" data-combobox-select><option value="">Loading...</option></select>
                            </div>
                        </label>
                    </div>
                    <label class="admin-field acct-field"><span>House no. / street</span><input data-psgc="street" placeholder="e.g. 143 Mabini St."></label>
                    <div class="admin-address-status" data-psgc-status role="status" aria-live="polite"></div>
                    <div class="admin-address-preview" role="status" aria-live="polite">
                        <?php echo admin_sidebar_icon('barangays'); ?>
                        <div>
                            <span class="admin-address-preview-label">Full address</span>
                            <input id="account_address" type="text" name="address" maxlength="255" readonly value="<?php echo $settingsEsc((string)($settingsProfile['address'] ?? '')); ?>">
                        </div>
                    </div>
                </div>
            </section>
        </form>

        <div class="acct-col-aside">
            <form method="post" class="admin-section acct-card" data-account-password-form data-validate-form>
                <input type="hidden" name="update_password" value="1">
                <header class="admin-section-head"><h2 class="admin-section-title">Change password</h2></header>
                <div class="admin-form-grid admin-form-grid--single acct-form-grid">
                    <label class="admin-field">
                        <span>Old password</span>
                        <span class="admin-password-control"><input id="account_old_password" type="password" name="old_password" autocomplete="current-password" placeholder="Enter current password"><button type="button" class="admin-password-toggle" data-password-toggle data-password-target="account_old_password" aria-label="Show old password"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-5 9.5-5 9.5 5 9.5 5-3.5 5-9.5 5-9.5-5-9.5-5Z"></path><circle cx="12" cy="12" r="2.5"></circle></svg></button></span>
                        <span class="admin-field-message"></span>
                    </label>
                    <label class="admin-field">
                        <span>New password</span>
                        <span class="admin-password-control"><input id="account_password" type="password" name="password" data-validate="password" data-label="New password" autocomplete="new-password" placeholder="Create a new password"><button type="button" class="admin-password-toggle" data-password-toggle data-password-target="account_password" aria-label="Show new password"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-5 9.5-5 9.5 5 9.5 5-3.5 5-9.5 5-9.5-5-9.5-5Z"></path><circle cx="12" cy="12" r="2.5"></circle></svg></button></span>
                        <span class="admin-field-message"></span>
                        <ul class="admin-pw-checklist" data-pw-checklist-for="account_password">
                            <li data-pw-rule="length">At least 8 characters</li><li data-pw-rule="upper">One uppercase letter</li><li data-pw-rule="lower">One lowercase letter</li><li data-pw-rule="number">One number</li><li data-pw-rule="special">One special character</li>
                        </ul>
                        <div class="acct-strength" data-account-strength><div class="acct-segments" data-acct-strength-bar><span class="acct-segment" data-acct-segment></span><span class="acct-segment" data-acct-segment></span><span class="acct-segment" data-acct-segment></span><span class="acct-segment" data-acct-segment></span></div><div class="acct-strength-label" data-acct-strength-label aria-live="polite"></div></div>
                    </label>
                    <label class="admin-field">
                        <span>Repeat new password</span>
                        <span class="admin-password-control"><input id="account_password_repeat" type="password" name="password_repeat" data-validate="confirm-password" data-label="Repeat new password" data-match="account_password" autocomplete="new-password" placeholder="Re-enter new password"><button type="button" class="admin-password-toggle" data-password-toggle data-password-target="account_password_repeat" aria-label="Show repeated password"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-5 9.5-5 9.5 5 9.5 5-3.5 5-9.5 5-9.5-5-9.5-5Z"></path><circle cx="12" cy="12" r="2.5"></circle></svg></button></span>
                        <span class="admin-field-message"></span>
                    </label>
                </div>
                <div class="acct-card-actions"><button type="submit" class="admin-btn" data-account-password-submit disabled>Update password</button></div>
            </form>
        </div>
    </div>
    <div class="acct-savebar" data-account-savebar><span class="acct-savebar-hint">You have unsaved changes.</span><div class="acct-savebar-actions"><button type="button" class="admin-btn-secondary" data-account-cancel>Cancel</button><button type="submit" form="account-profile-form" class="admin-btn" data-account-save disabled>Save changes</button></div></div>
</div>
