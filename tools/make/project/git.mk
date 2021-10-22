PHONY += copy-commit-message-script
copy-commit-message-script:
	@$(foreach name,$(shell find public/modules/*/* public/themes/*/* -type d -name ".git" -exec dirname {} \; 2> /dev/null ) .,cp tools/commit-msg $(name)/.git/hooks;)
