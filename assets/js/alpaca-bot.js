const accordions = document.getElementsByClassName("accordion-btn");
const alpaca_bot_settings = document.querySelector("#alpaca-bot-settings");
const chat_history_id = document.querySelector("#chat_history_id");
const images = document.querySelector("#images");
const message = document.querySelector("#message");
const models = document.querySelector("#model");
const prompt = document.querySelector("#prompt");
const response = document.querySelector("#ab-response");
const submit = document.querySelector("#submit");
const upload = document.querySelector("#upload");
const upload_remove = document.querySelector("#upload_remove");
const welcome = document.querySelector("#ab-hello");

// HTMx
htmx.config.defaultFocusScroll = true;
htmx.config.ignoreTitle = true;

// Render markdown to HTML per request
async function render(opts = {}) {
    // Ensure everything is initialized
    await this.waitForReady();

    // Start generating markdown HTML string in parallel
    const pending = this.buildMd(opts);

    // Insert or replace styles into DOM
    const styles = await this.stampStyles(this.buildStyles());
    await this.tick();

    // Insert or replace body into DOM
    const body = await this.stampBody(await pending, opts.classes);
}

function clearChat() {
    // Append to end of response
    var opResponse = document.querySelector("#ab-response");
    opResponse.innerHTML = '<div id="ab-dialog"></div>';
}

function clearHome() {
    // if ab-response is not empty, hide welcome message
    if (response.innerHTML != "") {
        welcome.style.display = "none";
    }
}

// When submit XHR is complete focus on the textarea #prompt and clear the value
function clearPrompt() {
    prompt.value = "";
    images.value = "";

    // update buttons
    updateBtnState();
}

// Copy message to prompt
function copyMessage() {
    // Store message in hidden field so we can clear the textarea
    prompt.value = message.value;

    // Clear message
    message.value = "";

    // Say hello to Abie
    message.placeholder = "Message Abie...";
    message.focus();
}

// Check if the message is empty
// https://stackoverflow.com/a/3261380/1007492
function isBlank(str) {
    return !str || /^\s*$/.test(str);
}

function copyToClipboard(id) {
    // build vars for response and button using id
    var btn = document.getElementById("copy-" + id);

    // copy response to clipboard
    var copyText = getResponseInnerHTML(id);

    // clean up the response
    copyText = copyText.trim();

    // copy and alert user
    navigator.clipboard.writeText(copyText).then(() => {
        // change button text
        btn.innerHTML = "inventory";

        // After sleep of 5 seconds, change button text back to copy
        setTimeout(function () {
            btn.innerHTML = "content_paste";
        }, 2600);
    });
}

// JS to append fadeout CSS class to the element after timeout
function fadeOut(id, timeout = 2600, class_name = "fadeOut") {
    var element = document.getElementById(id);

    // After timeout, add the class to the element
    setTimeout(function () {
        element.classList.add(class_name);
    }, timeout);
}

function getResponseInnerHTML(id) {
    // if id doesn't start with response-, add response-
    if (!id.startsWith("response-")) {
        id = "response-" + id;
    }

    var response = document.getElementById(id);

    // copy response to clipboard
    var html = response.innerHTML;

    // clean up the response
    html = html.trim();

    return html;
}

// After submit
function htmxOnComplete() {
    clearPrompt();

    // Prism highlight code blocks
    Prism.highlightAll();

    // Smooth scroll to message
    smoothScrollTo("loading");
}

function listenForEnter() {
    // If the Enter key is pressed without Shift and the window width is large "enough"
    message.addEventListener("keydown", (e) => {
        if (e.key === "Enter" && !e.shiftKey && window.innerWidth > 640) {
            e.preventDefault();
            if (!isBlank(message.value)) {
                submitClick();
            }
        }
    });
}

function listenForEscape() {
    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape") {
            // ask the user if we want to cancel the request
            if (confirm("Are you sure you want to cancel the request?")) {
                htmx.trigger("#submit", "htmx:abort");
                htmx.trigger("#chat_regenerate", "htmx:abort");
            }
        }
    });
}

function mediaUploader(button, field, get, callback = null) {
    var custom_uploader;
    jQuery(button).click(function (e) {
        e.preventDefault();
        if (custom_uploader) {
            custom_uploader.open();
            return;
        }
        custom_uploader = wp.media.frames.file_frame = wp.media({
            title: "Choose Image",
            button: {
                text: "Choose Image",
            },
            multiple: false,
        });
        custom_uploader.on("select", function () {
            var attachment = custom_uploader
                .state()
                .get("selection")
                .first()
                .toJSON();

            var url = attachment[get] ? attachment[get] : attachment.url;
            jQuery(field).val(url);

            if (callback) {
                callback();
            }
        });
        custom_uploader.open();
    });
}

function onClickChange() {
    htmx.onLoad(function (content) {
        // If the content is a form, clear the prompt and scroll to the bottom
        htmxOnComplete();
    });

    // Clear welcome screen
    clearHome();

    // Copy message to prompt, clear message
    copyMessage();
}

function performEventListener(
    input,
    action,
    target,
    scroll_to,
    callback = null
) {
    input.addEventListener(action, function () {
        // Do not submit if the message is empty
        if (isBlank(target.value)) {
            return;
        }

        // Scroll to indicator
        smoothScrollTo(scroll_to);

        // Submit form
        onClickChange(scroll_to);

        // Callback
        if (callback) {
            callback();
        }
    });
}

function promptEdit(id) {
    // get response innerHTML
    var html = getResponseInnerHTML(id);

    // strip all HTML tags
    html = html.replace(/<[^>]*>?/gm, "");

    // set prompt value to response innerHTML
    prompt.value = html;
    message.value = prompt.value;

    // Scroll to indicator
    smoothScrollTo("footer");
}

function promptResubmit(id) {
    // get response innerHTML
    var html = getResponseInnerHTML(id);

    // set prompt value to response innerHTML
    prompt.value = html;
    message.value = prompt.value;

    // Scroll to indicator
    smoothScrollTo("loading");

    // Submit form
    onClickChange();
}

// only show set_default_model after #model is clicked
function setDefaultModel(timeout = 5200) {
    var defaultModel = document.querySelector("#set_default_model");
    defaultModel.innerHTML = "Set as default";

    // update class list to only fadeIn
    defaultModel.classList = "fadeIn";

    // remove after timeout
    setTimeout(function () {
        defaultModel.classList = "fadeOut";
    }, timeout);
}

function showHide(id) {
    var element = document.getElementById(id);
    if (element.style.display === "none") {
        element.style.display = "block";
    } else {
        element.style.display = "none";
    }
}

// find element by class and scroll to it, parameter is the class name
function smoothScrollTo(
    selector = "footer",
    behavior = "smooth",
    block = "start"
) {
    switch (selector) {
        case "dialog":
            var opResponse = document.querySelector("#ab-response");
            var opDialogs = opResponse.querySelectorAll(".ab-dialog");
            var lastOpDialog = opDialogs[opDialogs.length - 1];
            var element = lastOpDialog ? lastOpDialog.id : null;

            // if there is no element with class of .ab-dialog, scroll to the bottom
            if (element == null) {
                element = opResponse;
            } else {
                element = document.querySelector("#" + element);
            }
            element.scrollIntoView({ behavior: behavior, block: block });
            break;

        case "bottom":
            element = document.querySelector("#ab-response");
            block = "end";
            break;

        case "footer":
            element = document.querySelector("#wpfooter");
            break;

        case "loading":
            element = document.querySelector("#indicator");
            break;

        default:
            element = document.querySelector(selector);
            break;
    }
    element.scrollIntoView({ behavior: behavior, block: block });
    console.log(element.id);
}

function submitClick() {
    updateBtnState();
    submit.click();
}

function updateBtnState() {
    if (images.value != "") {
        upload.innerHTML = "hide_image";
        // hide upload button
        upload.style.display = "none";
        // show remove button
        upload_remove.style.display = "block";
    } else {
        upload.innerHTML = "image";
        // show upload button
        upload.style.display = "block";
        // hide remove button
        upload_remove.style.display = "none";
    }
}

if (accordions) {
    var i;

    for (i = 0; i < accordions.length; i++) {
        accordions[i].addEventListener("click", function () {
            this.classList.toggle("active");
            var panel = this.nextElementSibling;
            if (panel.style.maxHeight) {
                panel.style.maxHeight = null;
            } else {
                panel.style.maxHeight = panel.scrollHeight + "px";
            }
        });
    }
}

if (alpaca_bot_settings) {
    mediaUploader(
        "#alpaca_bot_default_avatar_button",
        "#alpaca_bot_default_avatar"
    );
}

if (message) {
    listenForEscape();
    listenForEnter();

    // add media uploader
    mediaUploader("#upload", "#images", "id", updateBtnState);

    // remove media on upload_remove click without performEventListener
    upload_remove.addEventListener("click", function () {
        images.value = "";
        updateBtnState();
    });

    // On submit click, prevent default and submit form if message is not empty
    performEventListener(
        submit,
        "click",
        message,
        "loading",
        updateBtnState
    );

    // On #chat_history_id select change
    performEventListener(
        chat_history_id,
        "change",
        chat_history_id,
        "dialog",
        clearChat
    );

    // On page load focus on the textarea #prompt
    window.onload = function () {
        message.focus();
    };
}
