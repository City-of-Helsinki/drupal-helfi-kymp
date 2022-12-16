include $(DRUIDFI_TOOLS_MAKE_DIR)common.mk

ifeq ($(call has,docker),yes)
include $(DRUIDFI_TOOLS_MAKE_DIR)docker.mk
endif

include $(DRUIDFI_TOOLS_MAKE_DIR)qa.mk

#
# Apps
#

IS_DRUPAL ?= $(shell test -f $(WEBROOT)/sites/default/settings.php && echo yes || echo no)
IS_SYMFONY ?= $(shell test -f config/bundles.php && echo yes || echo no)
IS_WP ?= $(shell (test -f $(WEBROOT)/wp-settings.php || test -f conf/wp-config.php) && echo yes || echo no)

ifeq ($(IS_DRUPAL),yes)
include $(DRUIDFI_TOOLS_MAKE_DIR)drupal.mk
endif

ifeq ($(IS_SYMFONY),yes)
include $(DRUIDFI_TOOLS_MAKE_DIR)symfony.mk
endif

ifeq ($(IS_WP),yes)
include $(DRUIDFI_TOOLS_MAKE_DIR)wordpress.mk
endif

#
# Hosting systems
#

LAGOON := $(shell test -f .lagoon.yml && echo yes || echo no)
WODBY := $(shell test -f wodby.yml && echo yes || echo no)

ifeq ($(LAGOON),yes)
	SYSTEM := LAGOON
else ifeq ($(WODBY),yes)
	SYSTEM := WODBY
else
	SYSTEM := WHOKNOWS
endif

ifeq ($(SYSTEM),LAGOON)
include $(DRUIDFI_TOOLS_MAKE_DIR)lagoon.mk
endif

COMPOSER_JSON_EXISTS := $(shell test -f $(COMPOSER_JSON_PATH)/composer.json && echo yes || echo no)

ifeq ($(COMPOSER_JSON_EXISTS),yes)
include $(DRUIDFI_TOOLS_MAKE_DIR)composer.mk
endif

PACKAGE_JSON_EXISTS := $(shell test -f $(PACKAGE_JSON_PATH)/package.json && echo yes || echo no)

ifeq ($(PACKAGE_JSON_EXISTS),yes)
include $(DRUIDFI_TOOLS_MAKE_DIR)javascript.mk
endif
