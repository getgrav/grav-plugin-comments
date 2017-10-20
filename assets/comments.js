$(document).ready(function () {

    function PhpComment(element) {
        this.element = element;
        this.init();
    }

    PhpComment.prototype.init = function () {
        this.setupVariables();
        this.setupEvents();
    }

    PhpComment.prototype.setupVariables = function () {
        this.commentForm = this.element.find(".comment-form");
        this.titleField = this.element.find("#comment_title");
        this.bodyField = this.element.find("#comment_body");
    }

    PhpComment.prototype.setupEvents = function () {
        var phpComment = this,
        newMedia;

        $.ajax({
            url: '/media_template.php',
            method: 'GET',
            dataType: 'html',
            success: function (data) {
                newMedia = data;
            }
        });

        phpComment.commentForm.on("submit", function (e) {
            e.preventDefault();
            var parentId = 0,
                title = phpComment.titleField.val(),
                body = phpComment.bodyField.val();

            if(phpComment.commentForm.parents(".media").length > 0){
                parentId = phpComment.commentForm.closest(".media").attr("data-Id");
            }

            $.ajax({
                url: phpComment.commentForm.attr("action"),
                method: 'POST',
                dataType: 'json',
                data: {title: title, body: body, parentId: parentId},
                success: function (data) {
                    if(!data.created){
                        alert("Couldn't create comment");
                        return;
                    }

                    newMedia = newMedia.replace("{{id}}", data.id);
                    newMedia = newMedia.replace("{{title}}", title);
                    newMedia = newMedia.replace("{{body}}", body);
                    newMedia = newMedia.replace("{{nested}}", '');
                    phpComment.commentForm.before(newMedia);
                    phpComment.titleField.val("");
                    phpComment.bodyField.val("");
                }
            });
        });

        $(document).on("click", ".comment-add-new", function (e) {
            e.preventDefault();
            var media = $(this).closest(".comments");
            media.find(">.comment-body>.comment-text").after(phpComment.commentForm);
        });
        $(document).on("click", ".comment-add-reply", function (e) {
            e.preventDefault();
            var media = $(this).closest(".comment");
            media.find(">.comment-body>.comment-text").after(phpComment.commentForm);
        });
    }

    $.fn.phpComment = function (options) {
        new PhpComment(this);
        return this;
    }

    $(".comments").phpComment();

});
