#!/bin/bash

# This script creates symlinks from the local GIT repo into your EE install. It also copies some of the extension icons.

dirname=`dirname "$0"`

echo ""
echo "You are about to install Low NoSpam"
echo "-----------------------------------"
echo ""
echo "Enter the path to your ExpressionEngine Install without a trailing slash [ENTER]:"
read ee_path
echo "Enter your system folder name [ENTER]:"
read ee_system_folder

ln -s -f "$dirname"/system/extensions/ext.low_nospam_check.php "$ee_path"/"$ee_system_folder"/extensions/ext.low_nospam_check.php
ln -s -f "$dirname"/system/language/english/lang.low_nospam.php "$ee_path"/"$ee_system_folder"/language/english/lang.low_nospam.php
ln -s -f "$dirname"/system/language/english/lang.low_nospam_check.php "$ee_path"/"$ee_system_folder"/language/english/lang.low_nospam_check.php
ln -s -f "$dirname"/system/modules/low_nospam "$ee_path"/"$ee_system_folder"/modules/low_nospam