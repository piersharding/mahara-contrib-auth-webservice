{include file="header.tpl"}

    <p>{str tag="usersearchinstructions" section="str.webservice"}</p>
    <div id="initials">
      <label>{str tag="firstname"}:</label>
       <span class="{if !$search->f} selected{/if} all">
        <a href="{$WWWROOT}webservice/admin/search.php{if $search->l}?l={$search->l}{/if}">{str tag="All"}</a>
       </span>
       {foreach from=$alphabet item=a}
       <span class="{if $a == $search->f} selected{/if}">
        <a href="{$WWWROOT}webservice/admin/search.php?f={$a}{if $search->l}&amp;l={$search->l}{/if}">{$a}</a>
       </span>
       {/foreach}
	  <br />
      <label>{str tag="lastname"}:</label>
       <span class="{if !$search->l} selected{/if} all">
        <a href="{$WWWROOT}webservice/admin/search.php{if $search->f}?f={$search->f}{/if}">{str tag="All"}</a>
       </span>
       {foreach from=$alphabet item=a}
       <span class="{if $a == $search->l} selected{/if}">
        <a href="{$WWWROOT}webservice/admin/search.php?l={$a}{if $search->f}&amp;f={$search->f}{/if}">{$a}</a>
       </span>
       {/foreach}
    </div>
    <form action="{$WWWROOT}webservice/admin/search.php" method="post">
        <div class="searchform">
            <label>{str tag='Search' section='admin'}:</label>
                <input type="text" name="query" id="query"{if $search->query} value="{$search->query}"{/if}>
            
            {if count($institutions) > 1}
            <span class="institutions">
                <label>{str tag='Institution' section='admin'}:</label>
                    {if $USER->get('admin')}
                    <select name="institution" id="institution">
                    {else}
                    <select name="institution_requested" id="institution_requested">
                    {/if}
                        <option value="all"{if !$.request.institution} selected="selected"{/if}>{str tag=All}</option>
                        {foreach from=$institutions item=i}
                        <option value="{$i->name}"{if $i->name == $.request.institution}" selected="selected"{/if}>{$i->displayname}</option>
                        {/foreach}
                    </select>
            </span>
            {/if}
            <input type="hidden" name="token" id="token" value="{$token_id}" />
            <input type="hidden" name="suid" id="suid" value="{$suid}" />
            <input type="hidden" name="ouid" id="ouid" value="{$ouid}" />
            <button id="query-button" class="btn-search" type="submit">{str tag="go"}</button>
            <input type="submit" class="submitcancel cancel" id="cancel_submit" name="cancel_submit" value="{$cancel}">
        </div>
        <div id="results" class="section">
            {$results|safe}
        </div>
    </form>

{include file="footer.tpl"}
