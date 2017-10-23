jQuery(document).ready(function () {
  var commentForm = $(document).find('.comments-form');
  var commentSection = $(document).find('.comments').first();
  var commentAlert = commentForm.closest('.alert');
  //var newMedia;
  //hide form, show link
  commentForm.hide();
  $(document).find('.comment-add-new').show();
  //get template for inserting new comments
  /* 
$.ajax({
	url: '/media_template.php',
	method: 'GET',
	dataType: 'html',
	success: function (data) {
		newMedia = data;
	}
});
 */
  $('body').on('click', '.comment-add-new-sadf', function (e) {
    e.preventDefault();
    alert('asdf');
    $('span').stop().css('opacity', 1).text('myName = ' + e.name).fadeIn(30).fadeOut(1000);
  });
  //show comment form above comments section (new comment thread)
  $('body').on('click', '.comment-add-new', function (e) {
    e.preventDefault();
    //commentForm.hide(1000);
    //commentSection.before(commentForm);
    $(this).before(commentForm);
    commentForm.show('slow');
    //$(this).slideUp();
  });
  //show comment form below selected comment (reply to existing comment)
  $('body').on('click', '.comment-add-reply', function (e) {
    e.preventDefault();
    var media = $(this).closest('.comment');
    commentForm.hide();
    media.find('>.comment-body>.comment-text').after(commentForm);
    commentForm.show('slow');
  });
  // Attach a submit handler to the form
  $(commentForm).on('submit', function (event) {
    event.preventDefault();
    // Get some values from elements on the page:
    //var term = $(this).find( "input[name='s']" ).val();
    //var data = $(this).serializeArray();
    var data = $(this).serialize();
		console.log("Form Data (submit)", JSON.parse(JSON.stringify(data)));
    //var url = $(this).attr( "action" );
    var url = '/nested-comments';
    var parentId = 0;
    if ($(this).parents('.comment').length > 0) {
      parentId = $(this).closest('.comment').attr('data-Id');
    }
    // Send the data using post

    //var posting = $.post(url, { parentId: parentId, data: data }, null, 'json');
    var posting = $.post(url, data + '&parentID=' + parentId, null, 'json');
    //$.post( "test.php", $( "#testform" ).serialize() );
    // Put the results in a div
    posting.done(function (response) {
      alert('success');
		console.log("Response Data (done)", JSON.parse(JSON.stringify(response)));
      //response = JSON.parse(response);
      var message = response.status ? response.message : 'Error: ' + response.message;
      commentForm.after(commentAlert);
      commentAlert.empty().append(message);
      if (!response.status) {
        return;
      }
      if (response.status) {
		var newMedia = `
				<div class='comment comment-level-{{comment.level|e}}' data-Id='{{comment.id}}' >
				  <div class='comment-left'>
					<a href='#'>
					  <img class='comment-object' src='https://www.gravatar.com/avatar/{{comment.email|trim|lower|md5}}?d=identicon' alt='user icon'>
					</a>
				  </div>
				  <div class='comment-body'>
					<div class='comment-heading'>
						<div class='comment-title'><h4>{{comment.title}}</h4></div>
						<div class='comment-reply'><a class='comment-add-reply' href='#'>{{'PLUGIN_COMMENTS.ADD_REPLY'|t}}</a></div>
						<div class='comment-meta'>{{'PLUGIN_COMMENTS.WRITTEN_ON'|t}} {{comment.date|e}} {{'PLUGIN_COMMENTS.BY'|t}} {{comment.author}}</div>
					</div>
					<div class='comment-text' >
						{{comment.text}}
					</div>
					{{nested}}
				  </div>
				</div>
`;
        newMedia = newMedia.replace('{{comment.id}}', response.id);
        newMedia = newMedia.replace('{{comment.level|e}}', response.level);
        newMedia = newMedia.replace('{{comment.email|trim|lower|md5}}', response.hash);
        newMedia = newMedia.replace('{{parent_id}}', response.data.parent_id);
        newMedia = newMedia.replace('{{comment.title}}', response.data.title);
        newMedia = newMedia.replace('{{comment.text}}', response.data.text);
        newMedia = newMedia.replace('{{comment.author}}', response.data.name);
        //newMedia = newMedia.replace('{{comment.date|e}}', response.data.name);
		if ($( "div[data-Id='" + response.data.parent_id + "']" ).length > 0) {
			$( "div[data-Id='" + response.data.parent_id + "']" ).first().after(newMedia);
		} else {
			$( "div.comments" ).last().prepend(newMedia);
		}
        //phpComment.commentForm.before(newMedia);
        //phpComment.titleField.val("");
        //phpComment.bodyField.val("");
      }
      setTimeout(function () {
        commentForm.hide(3000);
      }, 5000);
    });
    posting.fail(function (status, error, title) {
      alert('error');
		console.log("Response Data (fail)", JSON.parse(JSON.stringify(status)));
      commentForm.after(commentAlert);
      commentAlert.empty().append("<p>TEST</p>");
      commentAlert.append("<p>" + status + "</p>");
      commentAlert.append("<p>" + error + "</p>");
      commentAlert.append("<p>" + title + "</p>");
    });
    posting.always(function (test) {
      //alert("finished, be it successful or not");
      //test = JSON.parse(test);
      //test = test.serialize();
	  //alert(test);
    });
  });
});
