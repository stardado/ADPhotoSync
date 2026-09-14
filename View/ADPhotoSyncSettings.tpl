{* Every label comes pre-translated in $T_TXT. The eF_dtranslate modifier is
   deliberately not used here: it calls dtranslate() directly, which does not
   guard its vsprintf, so a stray placeholder — from the catalogue or from the
   language override table — would replace this whole page with an error. *}
{eF_template_appendTitle title="ADPhotoSync" link="ADPhotoSync"}

{capture name="status"}
    <div class="ef-ad-photo-status">
        {if $T_STALE_CATALOGUE}
            <p class="ef-sidenote"><strong>{$T_STALE_CATALOGUE|escape}</strong></p>
        {/if}

        <table class="table">
            <tbody>
            <tr>
                <td>{$T_TXT.last_run|escape}</td>
                <td>
                    {if $T_LAST_RUN}
                        {$T_LAST_RUN|escape}
                        {if $T_LAST_RESULT} &mdash; {$T_LAST_RESULT|escape}{/if}
                    {else}
                        {$T_TXT.never|escape}
                    {/if}
                </td>
            </tr>
            <tr>
                <td>{$T_TXT.synced|escape}</td>
                <td>{$T_STATISTICS.synced}</td>
            </tr>
            <tr>
                <td>{$T_TXT.no_photo|escape}</td>
                <td>{$T_STATISTICS.no_photo}</td>
            </tr>
            <tr>
                <td>{$T_TXT.not_in_ad|escape}</td>
                <td>{$T_STATISTICS.not_in_ad}</td>
            </tr>
            <tr>
                <td>{$T_TXT.no_mail|escape}</td>
                <td>{$T_STATISTICS.no_mail}</td>
            </tr>
            <tr>
                <td>{$T_TXT.errors|escape}</td>
                <td>{$T_STATISTICS.failed}</td>
            </tr>
            <tr>
                <td>{$T_TXT.no_avatar|escape}</td>
                <td>{$T_STATISTICS.no_avatar}</td>
            </tr>
            <tr>
                <td>{$T_TXT.secondary|escape}</td>
                <td>{$T_STATISTICS.secondary}</td>
            </tr>
            </tbody>
        </table>

        {if $T_PASSWORD_FROM_ENV}
            <p class="ef-sidenote">{$T_TXT.bind_password|escape}: {$T_PASSWORD_ENV_VAR|escape}</p>
        {/if}

        {if $T_CONFIGURED}
            <a href="{$T_TEST_URL}" class="btn btn-default">{$T_TXT.test|escape}</a>
            <a href="{$T_CHECK_URL}" class="btn btn-default">{$T_TXT.check|escape}</a>
            <a href="{$T_SYNC_URL}" class="btn btn-primary">{$T_TXT.sync|escape}</a>
        {/if}
    </div>
{/capture}
{eF_template_printBlock data=$smarty.capture.status title=$T_TXT.overview}

{capture name="notinad"}
    <p class="ef-sidenote">{$T_TXT.not_in_ad_hint|escape}</p>
    {if $T_NOT_IN_AD_USERS}
        <table class="table">
            <thead>
            <tr>
                <th>{$T_TXT.login|escape}</th>
                <th>{$T_TXT.name|escape}</th>
                <th>{$T_TXT.checked|escape}</th>
            </tr>
            </thead>
            <tbody>
            {foreach $T_NOT_IN_AD_USERS as $entry}
                <tr>
                    <td>{$entry.login|escape}</td>
                    <td>{$entry.fullname|escape}</td>
                    <td>{$entry.checked|escape}</td>
                </tr>
            {/foreach}
            </tbody>
        </table>
        {if $T_STATISTICS.not_in_ad > $T_LIST_LIMIT}
            <p class="ef-sidenote">{$T_TXT.truncated|escape}</p>
        {/if}
    {else}
        <p class="ef-sidenote">{$T_TXT.nothing|escape}</p>
    {/if}
{/capture}
{eF_template_printBlock data=$smarty.capture.notinad title=$T_TXT.not_in_ad}

{capture name="nophoto"}
    <p class="ef-sidenote">{$T_TXT.no_photo_hint|escape}</p>
    {if $T_NO_PHOTO_USERS}
        <table class="table">
            <thead>
            <tr>
                <th>{$T_TXT.login|escape}</th>
                <th>{$T_TXT.name|escape}</th>
                <th>{$T_TXT.checked|escape}</th>
            </tr>
            </thead>
            <tbody>
            {foreach $T_NO_PHOTO_USERS as $entry}
                <tr>
                    <td>{$entry.login|escape}</td>
                    <td>{$entry.fullname|escape}</td>
                    <td>{$entry.checked|escape}</td>
                </tr>
            {/foreach}
            </tbody>
        </table>
        {if $T_STATISTICS.no_photo > $T_LIST_LIMIT}
            <p class="ef-sidenote">{$T_TXT.truncated|escape}</p>
        {/if}
    {else}
        <p class="ef-sidenote">{$T_TXT.nothing_yet|escape}</p>
    {/if}
{/capture}
{eF_template_printBlock data=$smarty.capture.nophoto title=$T_TXT.no_photo}

{if $T_NO_MAIL_USERS}
    {capture name="nomail"}
        <p class="ef-sidenote">{$T_TXT.no_mail_hint|escape}</p>
        <table class="table">
            <thead>
            <tr>
                <th>{$T_TXT.login|escape}</th>
                <th>{$T_TXT.name|escape}</th>
                <th>{$T_TXT.checked|escape}</th>
            </tr>
            </thead>
            <tbody>
            {foreach $T_NO_MAIL_USERS as $entry}
                <tr>
                    <td>{$entry.login|escape}</td>
                    <td>{$entry.fullname|escape}</td>
                    <td>{$entry.checked|escape}</td>
                </tr>
            {/foreach}
            </tbody>
        </table>
    {/capture}
    {eF_template_printBlock data=$smarty.capture.nomail title=$T_TXT.no_mail}
{/if}

{if $T_FAILED_USERS}
    {capture name="failed"}
        <p class="ef-sidenote">{$T_TXT.errors_hint|escape}</p>
        <table class="table">
            <thead>
            <tr>
                <th>{$T_TXT.login|escape}</th>
                <th>{$T_TXT.reason|escape}</th>
                <th>{$T_TXT.checked|escape}</th>
            </tr>
            </thead>
            <tbody>
            {foreach $T_FAILED_USERS as $entry}
                <tr>
                    <td>{$entry.login|escape}</td>
                    <td>{$entry.message|escape}</td>
                    <td>{$entry.checked|escape}</td>
                </tr>
            {/foreach}
            </tbody>
        </table>
    {/capture}
    {eF_template_printBlock data=$smarty.capture.failed title=$T_TXT.errors}
{/if}

{capture name="single"}
    <p class="ef-sidenote">{$T_TXT.single_hint|escape}</p>
    {eF_template_printForm form=$T_SINGLE_FORM}
{/capture}
{eF_template_printBlock data=$smarty.capture.single title=$T_TXT.single_title}

{capture name="settings"}
    {eF_template_printForm form=$T_FORM}
{/capture}
{eF_template_printBlock data=$smarty.capture.settings title=$T_TXT.settings_title}
