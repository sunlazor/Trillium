Trillium.terminal.commands.panel.logout = function (term) {
    term.pop();
    $.ajax(Trillium.urlGenerator.generate('user.sign.out'), {async: false});
};